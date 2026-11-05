<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CmsPage;
use App\Models\Combo;
use App\Models\DeliveryZone;
use App\Models\Media;
use App\Models\Review;
use App\Models\SiteSetting;
use App\Services\AppSettings;
use App\Services\Gateways\GatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Everything the customer website may read WITHOUT signing in. Read-only, published/available data only, branch chosen
 * explicitly in the URL (there is no signed-in user to scope by). Nothing here exposes costs, stock, staff or internal ids
 * beyond what a menu needs.
 */
class CatalogController extends Controller
{
    public function __construct(private AppSettings $settings) {}

    /** GET /web/site — brand, contact, ordering switches, payment choices. */
    public function site(GatewayManager $gateways)
    {
        $s = SiteSetting::current();
        $gateways->ensureCatalog();
        $online = [];
        foreach (GatewayManager::DRIVERS as $key => $class) {
            if (! $class::manual() && $gateways->usableConfig($key) !== null) { // manual-confirm methods (Bangla QR) need a cashier, so not offered online
                $online[] = ['key' => $key, 'label' => $class::label()];
            }
        }

        return response()->json(['data' => [
            'siteName' => $s->site_name ?: $this->settings->get('business.name'), 'tagline' => $s->tagline, 'primaryColor' => $s->primary_color,
            'logoUrl' => $s->logoMedia?->url(), 'faviconUrl' => $s->faviconMedia?->url(),
            'contactPhone' => $s->contact_phone ?: $this->settings->get('business.phone'), 'contactEmail' => $s->contact_email ?: $this->settings->get('business.email'),
            'address' => $this->settings->get('business.address'),
            'socialFacebook' => $s->social_facebook, 'socialInstagram' => $s->social_instagram,
            'locale' => $this->settings->get('locale.default'), 'timezone' => $this->settings->get('locale.timezone'),
            'currency' => ['code' => $this->settings->get('currency.code'), 'symbol' => $this->settings->get('currency.symbol'), 'decimals' => $this->settings->get('currency.decimals')],
            'ordering' => [
                'enabled' => (bool) $this->settings->get('web.ordering'), 'cod' => (bool) $this->settings->get('web.cod'),
                'pickup' => (bool) $this->settings->get('web.pickup'), 'minOrder' => (int) $this->settings->get('web.min_order'), 'online' => $online,
            ],
            'features' => ['reservations' => $this->settings->enabled('reservations')],
        ]]);
    }

    /** GET /web/branches */
    public function branches()
    {
        return response()->json(['data' => Branch::where('is_active', true)->orderBy('name')->get()->map(fn ($b) => [
            'id' => $b->id, 'name' => $b->name, 'address' => $b->address, 'phone' => $b->phone,
        ])]);
    }

    /** GET /web/branches/{branch}/menu */
    public function menu(string $branch)
    {
        $b = Branch::where('id', $branch)->where('is_active', true)->firstOrFail();

        $categories = Category::where('is_active', true)->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $b->id))
            ->with(['foodItems' => fn ($q) => $q->where('is_available', true)->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $b->id))
                ->orderBy('sort_order')->orderBy('name')->with(['modifierGroups' => fn ($q) => $q->orderBy('sort_order')->with(['modifiers' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])])])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn ($c) => $c->foodItems->isNotEmpty())->values();

        $combos = Combo::where('branch_id', $b->id)->with('items.foodItem')->get()->filter->isOrderableNow()->values();

        return response()->json(['data' => [
            'branch' => ['id' => $b->id, 'name' => $b->name, 'address' => $b->address, 'phone' => $b->phone],
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'description' => $c->description,
                'items' => $c->foodItems->map(fn ($i) => $this->item($i)),
            ]),
            'combos' => $combos->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'description' => $c->description, 'price' => (float) $c->price,
                'includes' => $c->items->map(fn ($ci) => ['name' => $ci->foodItem?->name, 'quantity' => $ci->quantity])->filter(fn ($x) => $x['name'])->values(),
            ]),
            'deliveryZones' => DeliveryZone::where('branch_id', $b->id)->where('is_active', true)->orderBy('name')->get()
                ->map(fn ($z) => ['id' => $z->id, 'name' => $z->name, 'fee' => (float) $z->delivery_fee]),
        ]]);
    }

    /** GET /web/pages/{slug} — only published CMS pages. */
    public function page(string $slug)
    {
        $p = CmsPage::where('slug', $slug)->where('is_published', true)->firstOrFail();

        return response()->json(['data' => [
            'slug' => $p->slug, 'title' => $p->title, 'sections' => $this->sections($p->sections ?? []),
            'metaTitle' => $p->meta_title, 'metaDescription' => $p->meta_description, 'ogImageUrl' => $p->ogMedia?->url(), 'updatedAt' => $p->updated_at,
        ]]);
    }

    /** GET /web/sitemap — what a sitemap.xml needs. */
    public function sitemap()
    {
        return response()->json(['data' => [
            'pages' => CmsPage::where('is_published', true)->get(['slug', 'updated_at'])->map(fn ($p) => ['slug' => $p->slug, 'updatedAt' => $p->updated_at]),
            'branches' => Branch::where('is_active', true)->get(['id', 'updated_at'])->map(fn ($b) => ['id' => $b->id, 'updatedAt' => $b->updated_at]),
        ]]);
    }

    /** GET /web/branches/{branch}/reviews — only what a manager has published. */
    public function reviews(Request $request, string $branch)
    {
        $rows = Review::where('branch_id', $branch)->where('is_published', true)->latest()->limit(30)->get();
        $all = Review::where('branch_id', $branch)->where('is_published', true);

        return response()->json(['data' => [
            'average' => round((float) $all->avg('rating'), 1), 'count' => (int) $all->count(),
            'reviews' => $rows->map(fn ($r) => ['name' => $r->reviewer_name ?: $r->customer?->name, 'rating' => (int) $r->rating, 'comment' => $r->comment, 'date' => $r->created_at->toDateString()]),
        ]]);
    }

    private function item($i): array
    {
        return [
            'id' => $i->id, 'name' => $i->name, 'slug' => $i->slug, 'description' => $i->description, 'price' => (float) $i->base_price,
            'imageUrl' => $i->image_path ? Storage::disk('public')->url($i->image_path) : null,
            'modifierGroups' => $i->modifierGroups->map(fn ($g) => [
                'id' => $g->id, 'name' => $g->name, 'required' => (bool) $g->is_required, 'min' => (int) $g->min_select, 'max' => (int) $g->max_select,
                'modifiers' => $g->modifiers->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'price' => (float) $m->price]),
            ])->filter(fn ($g) => $g['modifiers']->isNotEmpty())->values(),
        ];
    }

    /**
     * Public shape of a page's blocks. Only known block types and only whitelisted keys leave the server (a stored
     * block can't smuggle extra fields out), and media ids are turned into URLs of real, existing IMAGES only.
     */
    private function sections(array $sections): array
    {
        $blocks = collect($sections)->filter(fn ($s) => is_array($s) && in_array($s['type'] ?? null, ['hero', 'text', 'image', 'menu_highlight', 'gallery', 'cta'], true))->values();
        $ids = $blocks->flatMap(fn ($s) => array_merge([$s['mediaId'] ?? null], is_array($s['mediaIds'] ?? null) ? array_slice($s['mediaIds'], 0, 12) : []))
            ->filter(fn ($id) => is_string($id) && Str::isUuid($id))->unique()->values();
        $media = $ids->isEmpty() ? collect() : Media::whereIn('id', $ids)->where('mime_type', 'like', 'image/%')->get()->keyBy('id');
        $img = fn ($id) => ($m = is_string($id) ? $media->get($id) : null) ? ['url' => $m->url(), 'alt' => $m->alt_text] : null;

        return $blocks->map(fn ($s) => array_filter([
            'type' => $s['type'],
            'title' => isset($s['title']) && is_string($s['title']) ? $s['title'] : null,
            'body' => isset($s['body']) && is_string($s['body']) ? $s['body'] : null,
            'image' => $img($s['mediaId'] ?? null),
            'images' => is_array($s['mediaIds'] ?? null) ? collect(array_slice($s['mediaIds'], 0, 12))->map($img)->filter()->values()->all() : null,
        ], fn ($v) => $v !== null && $v !== []))->all();
    }
}
