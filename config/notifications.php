<?php

return [

    // Phase 10K: the notices about automatic events (holds that ran out, downpayments due soon, orders that may be
    // closed as Unclaimed, tomorrow's photoshoots) are sent by `notifications:system-events` every 15 minutes and,
    // when this is on, also lazily on page loads (at most every 5 minutes), so they still go out without cron.
    // phpunit.xml turns it off: that run depends on the clock, so a page's query count would change from one request
    // to the next; SystemNotificationsTest turns it on where it tests the page-load run.
    'lazy_system_events' => (bool) env('NOTIFICATIONS_LAZY_SYSTEM_EVENTS', true),

];
