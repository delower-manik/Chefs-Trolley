<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AppSettings;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private AppSettings $settings) {}

    /** GET /settings — everything, grouped (needs settings.manage). */
    public function index()
    {
        return response()->json(['data' => $this->grouped()]);
    }

    /** GET /settings/public — what any signed-in screen needs to format money/dates and hide disabled modules. */
    public function publicView()
    {
        $s = $this->settings->all();

        return response()->json(['data' => [
            'businessName' => $s['business.name'], 'locale' => $s['locale.default'], 'timezone' => $s['locale.timezone'],
            'currency' => ['code' => $s['currency.code'], 'symbol' => $s['currency.symbol'], 'decimals' => $s['currency.decimals']],
            'features' => collect($s)->filter(fn ($v, $k) => str_starts_with($k, 'features.'))->mapWithKeys(fn ($v, $k) => [substr($k, 9) => (bool) $v])->all(),
        ]]);
    }

    /** PUT /settings {"business.name": "...", ...} — partial update of known keys only. */
    public function update(Request $request)
    {
        $schema = AppSettings::schema();
        $unknown = array_diff(array_keys($request->all()), array_keys($schema));
        if ($unknown) {
            return response()->json(['code' => 'unknown_setting', 'message' => 'Unknown setting: '.implode(', ', array_slice($unknown, 0, 5)).'.'], 422);
        }
        // "business.name" contains a dot, which validation would read as nesting: validate each key on its own.
        $clean = [];
        $errors = [];
        foreach ($request->all() as $key => $value) {
            $v = validator(['v' => $value], ['v' => $schema[$key]['rules']]);
            $v->fails() ? $errors[$key] = $v->errors()->all() : $clean[$key] = $value;
        }
        if ($errors) {
            return response()->json(['code' => 'validation_error', 'message' => 'The given data was invalid.', 'errors' => $errors], 422);
        }

        $before = array_intersect_key($this->settings->all(), $clean);
        $this->settings->set($clean, $request->user()->id);
        $after = array_intersect_key($this->settings->all(), $clean);
        if ($before !== $after) {
            AuditLog::record(
                actorId: $request->user()->id, actorName: $request->user()->name, action: 'settings.updated', subjectType: User::class,
                subjectId: $request->user()->id, changes: ['before' => $before, 'after' => $after], ipAddress: $request->ip(),
            );
        }

        return response()->json(['data' => $this->grouped()]);
    }

    private function grouped(): array
    {
        $out = [];
        foreach (AppSettings::schema() as $key => $def) {
            $out[$def['group']][substr($key, strlen($def['group']) + 1)] = $this->settings->get($key);
        }

        return $out;
    }
}
