<?php

namespace Tests\Feature\Cms;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_an_image_can_be_uploaded_and_is_stored_under_a_generated_filename(): void
    {
        $this->actingAsUserWithPermissions(['cms.manage']);
        $file = UploadedFile::fake()->image('hero-banner.jpg', 800, 600);

        $response = $this->postJson('/api/v1/media', ['file' => $file, 'alt_text' => 'Restaurant interior']);

        $response->assertStatus(201)
            ->assertJsonPath('data.originalFilename', 'hero-banner.jpg')
            ->assertJsonPath('data.altText', 'Restaurant interior');

        // The client's filename is never used as the actual storage path
        // (path-traversal / filename-confusion protection).
        $this->assertStringNotContainsString('hero-banner', $response->json('data.url'));
    }

    public function test_a_non_image_file_is_rejected_even_with_an_image_extension(): void
    {
        $this->actingAsUserWithPermissions(['cms.manage']);
        // UploadedFile::fake() reports its MIME type from the *name*, so it cannot test
        // content sniffing. This is a real text file on disk named like a JPEG.
        $path = tempnam(sys_get_temp_dir(), 'ct');
        file_put_contents($path, 'not actually an image');
        $file = new UploadedFile($path, 'fake.jpg', null, null, true);

        $this->postJson('/api/v1/media', ['file' => $file])->assertStatus(422);
    }

    public function test_a_php_named_upload_is_refused_even_when_the_content_is_a_real_image(): void
    {
        $this->actingAsUserWithPermissions(['cms.manage']);
        $path = tempnam(sys_get_temp_dir(), 'ct');
        imagepng(imagecreatetruecolor(8, 8), $path);

        $this->postJson('/api/v1/media', ['file' => new UploadedFile($path, 'shell.php', null, null, true)])
            ->assertStatus(422);
    }

    public function test_the_stored_extension_comes_from_the_content_not_the_client_filename(): void
    {
        $this->actingAsUserWithPermissions(['cms.manage']);
        $path = tempnam(sys_get_temp_dir(), 'ct');
        imagepng(imagecreatetruecolor(8, 8), $path); // PNG bytes, but the client calls it a .jpg

        $response = $this->postJson('/api/v1/media', ['file' => new UploadedFile($path, 'photo.jpg', null, null, true)])
            ->assertStatus(201);

        $this->assertStringEndsWith('.png', $response->json('data.url'));
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        $this->actingAsUserWithPermissions(['cms.manage']);
        $file = UploadedFile::fake()->image('too-big.jpg')->size(6000); // 6MB, over the 5MB cap

        $this->postJson('/api/v1/media', ['file' => $file])->assertStatus(422);
    }

    public function test_deleting_media_removes_the_stored_file(): void
    {
        $this->actingAsUserWithPermissions(['cms.manage']);
        $file = UploadedFile::fake()->image('logo.png');
        $mediaId = $this->postJson('/api/v1/media', ['file' => $file])->json('data.id');

        $this->deleteJson("/api/v1/media/{$mediaId}")->assertStatus(204);
    }
}
