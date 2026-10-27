@props(['status'])

@php
    // Single source of truth for status -> tone (statuses from AGENTS.md section 8)
    $tones = [
        'amber' => ['submitted', 'under_review', 'awaiting_customer_info'],
        'blue' => ['awaiting_schedule_confirmation', 'reschedule_proposed', 'partially_paid'],
        'violet' => ['processing'],
        'green' => ['accepted', 'confirmed', 'ready_for_pickup', 'paid'],
        'gray' => ['completed', 'photoshoot_completed', 'cancelled'],
        'red' => ['rejected', 'unpaid'],
    ];

    $status = $status instanceof \BackedEnum ? $status->value : (string) $status;

    $tone = collect($tones)->search(fn ($statuses) => in_array($status, $statuses, true)) ?: 'gray';
@endphp

<x-ui.badge :tone="$tone" {{ $attributes }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</x-ui.badge>
