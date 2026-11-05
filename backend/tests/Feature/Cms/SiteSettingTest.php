<?php

namespace Tests\Feature\Cms;

use Tests\TestCase;

class SiteSettingTest extends TestCase
{
    public function test_settings_exist_by_default_without_any_setup_step(): void
    {
        $this->actingAsUserWithPermissions(['cms.manage']);

        $response = $this->getJson('/api/v1/site-settings');

        $response->assertStatus(200)->assertJsonPath('data.siteName', 'Chefs Trolley');
    }

    public function test_updating_settings_never_creates_a_second_row(): void
    {
        $this->actingAsUserWithPermissions(['cms.manage']);

        $this->putJson('/api/v1/site-settings', ['site_name' => 'My Restaurant'])
            ->assertStatus(200)->assertJsonPath('data.siteName', 'My Restaurant');

        $this->putJson('/api/v1/site-settings', ['tagline' => 'Best food in town'])
            ->assertStatus(200)->assertJsonPath('data.siteName', 'My Restaurant')
            ->assertJsonPath('data.tagline', 'Best food in town');

        $this->assertDatabaseCount('site_settings', 1);
    }

    public function test_primary_color_must_be_a_valid_hex_code(): void
    {
        $this->actingAsUserWithPermissions(['cms.manage']);

        $this->putJson('/api/v1/site-settings', ['primary_color' => 'not-a-color'])->assertStatus(422);
        $this->putJson('/api/v1/site-settings', ['primary_color' => '#00FF00'])->assertStatus(200);
    }
}
