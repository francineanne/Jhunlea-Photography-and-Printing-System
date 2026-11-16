<?php

/*
| The shop's payment rules (Phase 10B). The owner edits them in Settings, Payment rules (shop_settings); the values
| below are only their starting defaults, used until the owner saves their own, and `limits` are the ranges the form
| accepts. Read them through App\Support\ShopSettings only.
*/

return [

    'defaults' => [
        // Share of the confirmed price paid to hold a photoshoot; 0 = no downpayment (confirmed at once)
        'downpayment_percent' => 50,
        // The downpayment is due this many days before the shoot ...
        'downpayment_due_days' => 3,
        // ... but the customer always gets at least this many hours after the confirmation (never past the shoot)
        'downpayment_min_hours' => 24,
        // A printing order with a confirmed price from this amount is paid before printing (pesos)
        'prepay_from' => '300.00',
        // An unpaid order ready for pickup may be marked Unclaimed this many days after it became ready
        'unclaimed_after_days' => 7,
    ],

    'limits' => [
        'downpayment_percent' => [0, 100],
        'downpayment_due_days' => [0, 60],
        'downpayment_min_hours' => [1, 168],
        'prepay_from' => [0, 99999999],
        'unclaimed_after_days' => [1, 365],
    ],

    // When a voided downpayment sends a Confirmed booking back to "Awaiting downpayment", the new deadline is this
    // many hours from the void (never after the shoot starts)
    'repay_hours_after_void' => 24,

    // The reason a booking is cancelled with when its downpayment was not paid in time
    'expired_reason' => 'Downpayment not paid by the deadline.',

    // The preset reason of an owner cancel, offered only after the shoot's start time
    'no_show_reason' => 'Did not show up',

];
