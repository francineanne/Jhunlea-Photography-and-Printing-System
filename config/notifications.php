<?php

return [

    // Phase 10K: the notices about automatic events (holds that ran out, downpayments due soon, orders that may be
    // closed as Unclaimed, tomorrow's photoshoots) are sent by `notifications:system-events` every 15 minutes and,
    // when this is on, also lazily on page loads (at most every 5 minutes), so they still go out without cron.
    // phpunit.xml turns it off: that run depends on the clock, so a page's query count would change from one request
    // to the next; SystemNotificationsTest turns it on where it tests the page-load run.
    'lazy_system_events' => (bool) env('NOTIFICATIONS_LAZY_SYSTEM_EVENTS', true),

    // Phase 10O: the "Needs your attention" list on the first page after signing in. On by default. phpunit.xml turns
    // it off for the same reason as above (the first page of a test's session would read more than the next one);
    // UrgentItemsTest turns it on.
    'urgent_on_login' => (bool) env('NOTIFICATIONS_URGENT_ON_LOGIN', true),

];
