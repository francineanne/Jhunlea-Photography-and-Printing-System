@props(['title', 'center' => false])

{{-- Heading block of the login-family panels: Playfair title (28px, medium) and a short intro --}}
<div @class(['mb-6', 'text-center' => $center])>
    <h1 class="font-display text-[28px] font-medium leading-9 tracking-tight">{{ $title }}</h1>
    <p class="mt-2 text-sm leading-relaxed text-app-text-2">{{ $slot }}</p>
</div>
