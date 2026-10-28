<?php

/*
| Rules for the files customers send with a printing order. This is the one place that decides which file types
| are accepted, how big they may be, where they are kept and how many an order may hold.
*/

return [

    // A private disk (storage/app/private). Never 'public': files are served only through an authorized route
    'disk' => env('PRINTING_FILES_DISK', 'local'),

    'directory' => 'printing-orders',

    // No service may allow more than this, whatever its own limit says
    'max_file_size_mb' => 25,

    'max_files_per_order' => 10,

    // Highest copies and pages one order may ask for; stops absurd totals before they reach the database
    'max_quantity' => 10000,
    'max_pages' => 10000,

    /*
    | Accepted types. A file passes only when its extension AND the MIME type found by reading the file's own
    | bytes (not the one the browser sent) both belong to the same entry. Which of these a service accepts is
    | the service's own `allowed_file_types`. Word, Excel and PowerPoint files are zip packages, so a server
    | may report them as application/zip.
    */
    'types' => [
        'pdf' => [
            'extensions' => ['pdf'],
            'mimes' => ['application/pdf'],
        ],
        'jpg' => [
            'extensions' => ['jpg', 'jpeg'],
            'mimes' => ['image/jpeg'],
        ],
        'png' => [
            'extensions' => ['png'],
            'mimes' => ['image/png'],
        ],
        'docx' => [
            'extensions' => ['docx'],
            'mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        ],
        'pptx' => [
            'extensions' => ['pptx'],
            'mimes' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        ],
        'xlsx' => [
            'extensions' => ['xlsx'],
            'mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        ],
    ],

];
