@props(['package', 'variant' => 'primary', 'size' => 'md', 'label' => 'Book'])

{{-- Opens the booking wizard with this package chosen. A guest signs in first and comes back to the wizard. The owner
     does not book, so the button is not shown to the owner. --}}
@unless (auth()->user()?->role === \App\Enums\UserRole::Owner)
    <x-ui.button :href="route('customer.bookings.create', ['package' => $package->slug])" :variant="$variant" :size="$size" {{ $attributes }}>
        <x-ui.icon name="event_available" :size="18" />{{ $label }}<span class="sr-only"> {{ $package->name }}</span>
    </x-ui.button>
@endunless
