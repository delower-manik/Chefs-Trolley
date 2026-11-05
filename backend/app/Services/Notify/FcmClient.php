<?php

namespace App\Services\Notify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Firebase Cloud Messaging HTTP v1 with a service account (OAuth2 JWT signed with openssl; no SDK). */
class FcmClient
{
    public static function isConfigured(): bool
    {
        return (bool) config('notify.fcm.project_id') && (bool) config('notify.fcm.credentials');
    }

    /** @return array{ok: bool, unregistered: bool, error: ?string, retryable: bool} */
    public function send(string $token, string $title, string $body, array $data = []): array
    {
        if (! self::isConfigured()) {
            return ['ok' => false, 'unregistered' => false, 'error' => 'push_not_configured', 'retryable' => false];
        }

        try {
            $project = config('notify.fcm.project_id');
            $r = Http::timeout((int) config('notify.fcm.timeout', 10))->withToken($this->accessToken())
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'notification' => ['title' => $title, 'body' => $body],
                        'data' => array_map('strval', $data),
                    ],
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'unregistered' => false, 'error' => 'fcm: '.$e->getMessage(), 'retryable' => true];
        }

        if ($r->successful()) {
            return ['ok' => true, 'unregistered' => false, 'error' => null, 'retryable' => false];
        }

        $status = (string) $r->json('error.status');
        $dead = in_array($status, ['NOT_FOUND', 'UNREGISTERED'], true) || $r->status() === 404;

        return [
            'ok' => false, 'unregistered' => $dead,
            'error' => "fcm HTTP {$r->status()}: ".substr($r->body(), 0, 200),
            'retryable' => $r->serverError() || $r->status() === 429,
        ];
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm.access_token', 3000, function () {
            $sa = $this->credentials();
            $now = time();
            $claims = [
                'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
            ];
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode($claims));
            if (! openssl_sign($unsigned, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('Could not sign the FCM JWT (check the service-account private key).');
            }

            $r = Http::timeout(10)->asForm()->post($claims['aud'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned.'.'.$b64($signature),
            ]);
            if (! $r->successful() || ! $r->json('access_token')) {
                throw new \RuntimeException('FCM token exchange failed: HTTP '.$r->status());
            }

            return $r->json('access_token');
        });
    }

    private function credentials(): array
    {
        $raw = (string) config('notify.fcm.credentials');
        if (is_file($raw)) {
            $raw = (string) file_get_contents($raw);
        } elseif (! str_starts_with(ltrim($raw), '{')) {
            $raw = (string) base64_decode($raw, true);
        }
        $sa = json_decode($raw, true);
        if (! is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new \RuntimeException('FCM credentials are not a valid service-account JSON.');
        }

        return $sa;
    }
}
