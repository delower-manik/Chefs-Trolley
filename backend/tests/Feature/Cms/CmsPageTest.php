<?php

namespace Tests\Feature\Cms;

use App\Models\CmsPage;
use Tests\TestCase;

class CmsPageTest extends TestCase
{
    public function test_a_page_starts_unpublished_and_can_be_published(): void
    {
        $this->actingAsUserWithPermissions(['cms.manage']);

        $create = $this->postJson('/api/v1/cms/pages', [
            'slug' => 'about-us', 'title' => 'About Us',
            'sections' => [['type' => 'hero', 'title' => 'Welcome']],
        ]);
        $create->assertStatus(201)->assertJsonPath('data.isPublished', false);

        $pageId = $create->json('data.id');
        $this->postJson("/api/v1/cms/pages/{$pageId}/publish")
            ->assertStatus(200)->assertJsonPath('data.isPublished', true);

        $this->postJson("/api/v1/cms/pages/{$pageId}/unpublish")
            ->assertStatus(200)->assertJsonPath('data.isPublished', false);
    }

    public function test_two_pages_cannot_share_a_slug(): void
    {
        $this->actingAsUserWithPermissions(['cms.manage']);
        CmsPage::create(['slug' => 'menu', 'title' => 'Our Menu']);

        $this->postJson('/api/v1/cms/pages', ['slug' => 'menu', 'title' => 'Duplicate'])
            ->assertStatus(422);
    }

    public function test_a_section_must_be_a_known_type(): void
    {
        $this->actingAsUserWithPermissions(['cms.manage']);

        $this->postJson('/api/v1/cms/pages', [
            'slug' => 'home', 'title' => 'Home', 'sections' => [['type' => 'raw_html', 'html' => '<script>bad</script>']],
        ])->assertStatus(422);
    }

    public function test_a_page_can_be_edited_with_a_partial_update(): void
    {
        $this->actingAsUserWithPermissions(['cms.manage']);
        $page = CmsPage::create(['slug' => 'contact', 'title' => 'Contact']);

        $this->putJson("/api/v1/cms/pages/{$page->id}", ['meta_description' => 'Get in touch with us'])
            ->assertStatus(200)->assertJsonPath('data.metaDescription', 'Get in touch with us');
    }
}
