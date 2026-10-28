<?php

/*
| Rules for photography bookings that the owner does not edit. The schedule rules the owner can change (opening
| hours, blocked dates, buffer, notice, how far ahead, sessions at once) live in the database; the values below are
| only their starting defaults, used until the owner saves their own.
*/

return [

    // A private disk (storage/app/private). Never 'public': reference images are served only through an authorized route
    'disk' => env('BOOKING_IMAGES_DISK', 'local'),

    'directory' => 'booking-images',

    'max_images_per_booking' => 5,

    // Keep it at or below PHP's upload_max_filesize (10M on XAMPP): PHP drops a bigger file before Laravel can explain why
    'max_image_size_mb' => 5,

    // All images of one booking together. Keep it below PHP's post_max_size (40M on XAMPP), which also counts the rest
    // of the form
    'max_total_size_mb' => 15,

    /*
    | Accepted reference images. A file passes only when its extension AND the MIME type found by reading the file's own
    | bytes (not the one the browser sent) both belong to the same entry. Same shape as printing.types.
    */
    'types' => [
        'jpg' => [
            'extensions' => ['jpg', 'jpeg'],
            'mimes' => ['image/jpeg'],
        ],
        'png' => [
            'extensions' => ['png'],
            'mimes' => ['image/png'],
        ],
        'webp' => [
            'extensions' => ['webp'],
            'mimes' => ['image/webp'],
        ],
    ],

    // Slots start every this many minutes from opening time (9:00, 9:30, 10:00 ...)
    'slot_step_minutes' => 30,

    // Starting values of the owner's schedule settings (booking_settings), used until the owner saves their own
    'defaults' => [
        'buffer_minutes' => 15,
        'min_notice_hours' => 24,
        'max_advance_days' => 60,
        'concurrent_sessions' => 1,
    ],

    // A customer may cancel a CONFIRMED booking themselves until this many hours before it starts; after that they
    // talk to the studio. Requested and proposed bookings can be cancelled at any time
    'customer_cancel_cutoff_hours' => 24,

    // How many requests (Requested or Proposed) one customer may have waiting at the same time
    'max_open_requests_per_customer' => 3,

    // The most days one availability lookup may cover, so a single request cannot ask for a year of slots
    'max_lookup_days' => 62,

];
