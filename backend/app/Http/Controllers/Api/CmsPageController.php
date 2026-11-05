<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCmsPageRequest;
use App\Models\AuditLog;
use App\Models\CmsPage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CmsPageController extends Controller
{
    public function index(Request $request)
    {
        $pages = CmsPage::query()
            ->when($request->has('published'), fn ($q) => $q->where('is_published', $request->boolean('published')))
            ->orderBy('title')
            ->get();

        return response()->json(['data' => $pages->map($this->serialize(...))]);
    }

    public function show(CmsPage $cmsPage)
    {
        return response()->json(['data' => $this->serialize($cmsPage)]);
    }

    public function store(SaveCmsPageRequest $request)
    {
        $page = CmsPage::create([
            ...$request->validated(),
            'slug' => $request->validated('slug') ?? Str::slug($request->validated('title')),
            'updated_by' => $request->user()->id,
        ]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'cms_page.created',
            subjectType: CmsPage::class,
            subjectId: $page->id,
            changes: ['after' => $page->only(['slug', 'title'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($page)], 201);
    }

    public function update(SaveCmsPageRequest $request, CmsPage $cmsPage)
    {
        $before = $cmsPage->only(['title', 'is_published']);
        $cmsPage->update([...$request->validated(), 'updated_by' => $request->user()->id]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'cms_page.updated',
            subjectType: CmsPage::class,
            subjectId: $cmsPage->id,
            changes: ['before' => $before, 'after' => $cmsPage->fresh()->only(['title', 'is_published'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($cmsPage->fresh())]);
    }

    /**
     * POST /cms/pages/{cmsPage}/publish and /unpublish
     * Publishing is a distinct, explicit, audited action -- separate from
     * saving draft edits -- so a half-finished edit can never accidentally
     * go live via a generic PUT.
     */
    public function publish(Request $request, CmsPage $cmsPage)
    {
        $cmsPage->update(['is_published' => true, 'published_at' => now()]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'cms_page.published',
            subjectType: CmsPage::class,
            subjectId: $cmsPage->id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($cmsPage->fresh())]);
    }

    public function unpublish(Request $request, CmsPage $cmsPage)
    {
        $cmsPage->update(['is_published' => false]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'cms_page.unpublished',
            subjectType: CmsPage::class,
            subjectId: $cmsPage->id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($cmsPage->fresh())]);
    }

    public function destroy(Request $request, CmsPage $cmsPage)
    {
        $cmsPage->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'cms_page.deleted',
            subjectType: CmsPage::class,
            subjectId: $cmsPage->id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(CmsPage $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'sections' => $page->sections ?? [],
            'metaTitle' => $page->meta_title,
            'metaDescription' => $page->meta_description,
            'ogMediaId' => $page->og_media_id,
            'ogMediaUrl' => $page->ogMedia?->url(),
            'isPublished' => $page->is_published,
            'publishedAt' => $page->published_at,
            'updatedAt' => $page->updated_at,
        ];
    }
}
