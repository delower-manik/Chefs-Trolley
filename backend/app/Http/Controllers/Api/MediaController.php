<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadMediaRequest;
use App\Models\AuditLog;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 40), 100);
        $paginator = Media::query()->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map($this->serialize(...)),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /media
     * A real upload to local disk storage (the `public` disk) -- validated
     * by both extension and actual MIME type, size-capped, and stored
     * under a generated filename (never the client's original filename)
     * so a crafted filename can't be used for a path-traversal or
     * extension-confusion trick.
     */
    public function store(UploadMediaRequest $request)
    {
        $file = $request->file('file');
        // The extension comes from the file's detected content type, never from
        // the client-supplied name: a real PNG uploaded as "shell.php" must be
        // stored as .png, or a web server could later execute it as PHP.
        $generatedName = Str::uuid()->toString().'.'.$file->extension();
        $path = $file->storeAs('media/'.now()->format('Y/m'), $generatedName, 'public');

        $media = Media::create([
            'disk' => 'public',
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'alt_text' => $request->validated('alt_text'),
            'uploaded_by' => $request->user()->id,
        ]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'media.uploaded',
            subjectType: Media::class,
            subjectId: $media->id,
            changes: ['filename' => $media->original_filename, 'sizeBytes' => $media->size_bytes],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($media)], 201);
    }

    public function destroy(Request $request, Media $media)
    {
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'media.deleted',
            subjectType: Media::class,
            subjectId: $media->id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(Media $media): array
    {
        return [
            'id' => $media->id,
            'url' => $media->url(),
            'originalFilename' => $media->original_filename,
            'mimeType' => $media->mime_type,
            'sizeBytes' => $media->size_bytes,
            'altText' => $media->alt_text,
            'createdAt' => $media->created_at,
        ];
    }
}
