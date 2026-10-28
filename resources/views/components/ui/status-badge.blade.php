@props(['status', 'live' => false])

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

    // Photography bookings (App\Enums\BookingStatus) use the booking designs' colours and the enum's own words:
    // waiting for the studio is amber, a new time waiting for the customer is blue
    $bookingTones = [
        'requested' => 'amber',
        'proposed' => 'blue',
        'confirmed' => 'green',
        'rejected' => 'red',
        'cancelled' => 'gray',
        'completed' => 'gray',
    ];

    $booking = $status instanceof \App\Enums\BookingStatus;
    $label = $booking ? $status->label() : null;
    $status = $status instanceof \BackedEnum ? $status->value : (string) $status;

    $tone = $booking
        ? $bookingTones[$status]
        : (collect($tones)->search(fn ($statuses) => in_array($status, $statuses, true)) ?: 'gray');

    // live: the dot moves as in the designs. Work in progress breathes; ready for pickup and a request waiting for the
    // studio send out a ring (the "pending review" pill of the bookings design)
    $pulse = ! $live ? null : ($booking
        ? match ($status) {
            'requested' => 'ping',
            'proposed' => 'pulse',
            default => null,
        }
        : match ($status) {
            'submitted', 'under_review', 'processing', 'awaiting_customer_info', 'awaiting_schedule_confirmation' => 'pulse',
            'ready_for_pickup' => 'ping',
            default => null,
        });
@endphp

<x-ui.badge :tone="$tone" :pulse="$pulse" {{ $attributes }}>{{ $label ?? ucfirst(str_replace('_', ' ', $status)) }}</x-ui.badge>
