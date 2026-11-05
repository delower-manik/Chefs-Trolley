<?php

namespace App\Exceptions;

use App\Models\Promotion;
use Exception;

/**
 * Thrown when a promotion's usage_limit is hit by a conditional atomic
 * increment (`UPDATE ... WHERE used_count < limit`) inside the order
 * transaction -- i.e. a second checkout raced this one for the last
 * remaining use of a limited-quantity promo code and won.
 */
class PromotionExhaustedException extends Exception
{
    public function __construct(public readonly Promotion $promotion)
    {
        parent::__construct("The promo code \"{$promotion->code}\" has just run out of uses.");
    }
}
