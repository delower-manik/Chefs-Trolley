<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital Presence domain — architecture §5 "Digital Presence". A
 * restaurant group in this system has one public website (not one per
 * branch), so none of these tables are branch-scoped -- matches how the
 * Website Integration module in the architecture doc talks about "the
 * customer website", singular.
 *
 * `cms_pages.sections` is a structured, typed JSON array (e.g.
 * [{"type":"hero","title":"...","imageMediaId":"..."}, {"type":"text",...}]),
 * never raw HTML -- per 02_CLAUDE_DEVELOPMENT_RULES.md §8/§9, this is a
 * template/content management engine, not an HTML editor a manager could
 * inject arbitrary markup or scripts through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('disk')->default('public');
            $table->string('path'); // storage path, e.g. media/2026/01/abc123.jpg
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('alt_text')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('cms_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('title');
            $table->json('sections')->nullable(); // ordered array of typed content blocks
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->uuid('og_media_id')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('og_media_id')->references('id')->on('media')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        // Singleton-style: the application always reads/writes the single
        // row with the lowest id (see SiteSetting::current()), rather than
        // this being a real multi-row resource.
        Schema::create('site_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('site_name')->default('Chefs Trolley');
            $table->string('tagline')->nullable();
            $table->string('primary_color', 7)->default('#D60000'); // hex
            $table->uuid('logo_media_id')->nullable();
            $table->uuid('favicon_media_id')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('social_facebook')->nullable();
            $table->string('social_instagram')->nullable();
            $table->timestamps();

            $table->foreign('logo_media_id')->references('id')->on('media')->nullOnDelete();
            $table->foreign('favicon_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('cms_pages');
        Schema::dropIfExists('media');
    }
};
