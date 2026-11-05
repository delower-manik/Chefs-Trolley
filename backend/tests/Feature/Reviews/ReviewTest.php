<?php

namespace Tests\Feature\Reviews;

use Tests\TestCase;

class ReviewTest extends TestCase
{
    public function test_a_new_review_starts_unpublished_and_can_be_moderated(): void
    {
        $this->actingAsUserWithPermissions(['reviews.view', 'reviews.moderate']);

        $create = $this->postJson('/api/v1/reviews', [
            'reviewer_name' => 'Happy Guest', 'rating' => 5, 'comment' => 'Great food!',
        ]);
        $create->assertStatus(201)->assertJsonPath('data.isPublished', false);

        $reviewId = $create->json('data.id');

        // Unpublished reviews still show up in an unfiltered admin listing...
        $this->getJson('/api/v1/reviews')->assertJsonCount(1, 'data');

        // ...but not when filtering for published only.
        $this->getJson('/api/v1/reviews?published=1')->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/reviews/{$reviewId}/publish")->assertStatus(200)->assertJsonPath('data.isPublished', true);
        $this->getJson('/api/v1/reviews?published=1')->assertJsonCount(1, 'data');
    }

    public function test_a_review_needs_either_a_customer_or_a_reviewer_name(): void
    {
        $this->actingAsUserWithPermissions(['reviews.view']);

        $this->postJson('/api/v1/reviews', ['rating' => 4])->assertStatus(422);
    }

    public function test_rating_must_be_between_1_and_5(): void
    {
        $this->actingAsUserWithPermissions(['reviews.view']);

        $this->postJson('/api/v1/reviews', ['reviewer_name' => 'Guest', 'rating' => 6])->assertStatus(422);
    }
}
