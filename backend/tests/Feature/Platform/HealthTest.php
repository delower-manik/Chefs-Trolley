<?php

namespace Tests\Feature\Platform;

use App\Services\Health;
use App\Services\OpenApi\OpenApiGenerator;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_public_health_is_ok_and_shows_status_only(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', 'ok')->assertJsonPath('checks.cache', 'ok')->assertJsonPath('checks.storage', 'ok')
            ->assertJsonMissingPath('details');
    }

    public function test_details_need_the_token(): void
    {
        config(['app.health_token' => 's3cret']);
        Cache::put(Health::HEARTBEAT_KEY, time() - 30, 60);
        $this->getJson('/api/v1/health', ['X-Health-Token' => 'wrong'])->assertOk()->assertJsonMissingPath('details');
        $this->getJson('/api/v1/health', ['X-Health-Token' => 's3cret'])->assertOk()
            ->assertJsonPath('details.queue_waiting', 0)->assertJsonPath('details.failed_jobs', 0)
            ->assertJsonPath('details.scheduler_last_seen_seconds_ago', fn ($v) => $v >= 29 && $v < 60);
    }

    public function test_a_failing_check_returns_503(): void
    {
        $this->mock(Health::class)->shouldReceive('run')->andReturn(['ok' => false, 'checks' => ['database' => 'fail', 'cache' => 'ok', 'storage' => 'ok'], 'details' => []]);
        $this->getJson('/api/v1/health')->assertStatus(503)->assertJsonPath('status', 'degraded')->assertJsonPath('checks.database', 'fail');
    }

    public function test_a_throwing_probe_is_reported_as_fail_without_the_error_text(): void
    {
        $m = new \ReflectionMethod(Health::class, 'probe');
        $out = $m->invoke(new Health, fn () => throw new \RuntimeException('SQLSTATE password=hunter2'));
        $this->assertSame('fail', $out);
    }

    public function test_it_needs_no_login_and_is_in_the_spec(): void
    {
        $this->assertArrayNotHasKey('security', app(OpenApiGenerator::class)->build()['paths']['/v1/health']['get']);
    }
}
