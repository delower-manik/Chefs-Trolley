<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\Review;
use App\Services\Webhooks\Webhooks;
use Illuminate\Database\Eloquent\Model;

/** Fires review.created / customer.created webhooks. */
class WebhookEventObserver
{
    public bool $afterCommit = true;

    public function created(Model $model): void
    {
        if ($model instanceof Review) {
            Webhooks::fire('review.created', [
                'id' => $model->id, 'orderId' => $model->order_id ?? null, 'rating' => $model->rating ?? null,
                'comment' => $model->comment ?? null, 'branchId' => $model->branch_id ?? null,
            ]);
        } elseif ($model instanceof Customer) {
            Webhooks::fire('customer.created', ['id' => $model->id, 'name' => $model->name, 'branchId' => $model->branch_id ?? null]);
        }
    }
}
