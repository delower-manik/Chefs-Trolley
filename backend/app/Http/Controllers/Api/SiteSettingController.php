<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSiteSettingRequest;
use App\Models\AuditLog;
use App\Models\SiteSetting;

class SiteSettingController extends Controller
{
    public function show()
    {
        return response()->json(['data' => $this->serialize(SiteSetting::current())]);
    }

    public function update(SaveSiteSettingRequest $request)
    {
        $settings = SiteSetting::current();
        $before = $settings->only(['site_name', 'primary_color']);
        $settings->update($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'site_settings.updated',
            subjectType: SiteSetting::class,
            subjectId: $settings->id,
            changes: ['before' => $before, 'after' => $settings->fresh()->only(['site_name', 'primary_color'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($settings->fresh())]);
    }

    private function serialize(SiteSetting $settings): array
    {
        return [
            'id' => $settings->id,
            'siteName' => $settings->site_name,
            'tagline' => $settings->tagline,
            'primaryColor' => $settings->primary_color,
            'logoMediaId' => $settings->logo_media_id,
            'logoUrl' => $settings->logoMedia?->url(),
            'faviconMediaId' => $settings->favicon_media_id,
            'faviconUrl' => $settings->faviconMedia?->url(),
            'contactPhone' => $settings->contact_phone,
            'contactEmail' => $settings->contact_email,
            'socialFacebook' => $settings->social_facebook,
            'socialInstagram' => $settings->social_instagram,
        ];
    }
}
