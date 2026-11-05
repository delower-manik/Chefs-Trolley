<?php

return [
    // Largest manual discount (percent of subtotal + exclusive tax) that a user with
    // `discounts.apply` may give. `discounts.override` has no cap.
    'max_discount_percent' => (float) env('POS_MAX_DISCOUNT_PERCENT', 20),

    // Accepted tender methods.
    'payment_methods' => ['cash', 'card', 'mobile_banking', 'gift_card', 'other'],

    // Business name printed on receipts when the branch has no own header.
    'receipt_footer' => env('POS_RECEIPT_FOOTER', 'Thank you for dining with us!'),
];
