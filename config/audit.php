<?php

// The audit trail (Phase 8E): an append-only log of important actions, written in the same transaction as the action,
// chained by SHA-256 so an edited, deleted or reordered row is detectable (not impossible) with direct database access.
return [

    // How long rows are kept before `php artisan audit:prune --force` may delete them. Never pruned automatically.
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 730),

    // The Activity log page checks this many of the newest rows on every view (cheap); `audit:verify` checks them all.
    'quick_check_rows' => 200,

    // The prev_hash of the very first row.
    'genesis' => 'jhunlea-audit-genesis-v1',

    // Rows per page and per chunk of the full check.
    'per_page' => 25,
    'verify_chunk' => 1000,

    // A failed owner login writes at most one row per IP in this many minutes.
    'failed_login_window_minutes' => 10,
];
