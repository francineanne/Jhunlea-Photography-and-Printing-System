@props(['shop' => [], 'links' => [], 'hasFaq' => false, 'policy' => null, 'bottomRoom' => false])

{{--
    Phase 10G: the ONE site footer (public and customer layouts), on the page's own container width.
    - Brand: the logo, one line about the shop and, where the page has no payment bar at the top (customer pages, error
      pages), the current payment line (PaymentRules::shortLine()).
    - Explore: the public pages as a vertical list.
    - Contact: the same details as the landing page's "Visit us", as compact rows without labels: one icon column, every
      row the same gap, long text wrapping under its own text. Only what the owner filled in; no column when none is set.
    - Bottom bar under a divider: the copyright on the left, "Terms and privacy" on the right.
    Three columns from 1024px, two from 768px (brand on top, full width), one on phones. Every link is at least 44px tall
    on phones.
--}}
@php
    $shop ??= [];
    $phone = filled($shop['phone'] ?? null) ? $shop['phone'] : null;
    $hasContact = filled($shop['address'] ?? null) || filled($shop['opening_hours'] ?? null) || $phone || filled($shop['email'] ?? null) || filled($shop['facebook_url'] ?? null);
    // The public pages, the same on every layout (the FAQ only when a question is published)
    $explore = $links ?: [
        ['label' => 'Printing services', 'route' => 'services.index'],
        ['label' => 'Photography packages', 'route' => 'packages.index'],
        ['label' => 'Package finder', 'route' => 'packages.finder'],
    ];
    if ($hasFaq) {
        $explore[] = ['label' => 'FAQ', 'route' => 'faq'];
    }
    // One row: icon column + text. Same padding on every row, so the gap between rows is the same everywhere
    $row = 'flex items-start gap-2.5 rounded py-3 sm:py-1';
    $link = 'hover:text-brand focus-ring dark:hover:text-[#ffb4ac]';
    $icon = 'mt-px shrink-0 text-brand dark:text-[#ffb4ac]';
    $heading = 'text-[11px] font-bold uppercase tracking-wider text-app-text';
@endphp

<footer class="border-t border-app-border bg-neutral-100 dark:bg-white/5" data-site-footer>
    <div @class(['mx-auto max-w-[1280px] px-4 pt-10 sm:px-6', 'pb-24' => $bottomRoom, 'pb-6' => ! $bottomRoom])>
        <div @class(['grid gap-x-12 gap-y-8 md:grid-cols-2', 'lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)_minmax(0,1.2fr)]' => $hasContact, 'lg:grid-cols-[minmax(0,1.2fr)_minmax(0,2fr)]' => ! $hasContact])>
            {{-- Brand --}}
            <div @class(['min-w-0 space-y-3', 'md:col-span-2 lg:col-span-1' => $hasContact])>
                <x-ui.logo />
                <p class="max-w-sm text-sm leading-relaxed text-app-text-2">Photography sessions and printing under one roof in Nasugbu, Batangas.</p>
                @if (filled($policy))
                    <p class="flex max-w-sm items-start gap-2 text-xs leading-relaxed text-app-text-2" data-footer-policy>
                        <x-ui.icon name="storefront" :size="16" class="{{ $icon }}" /><span class="min-w-0">{{ $policy }}</span>
                    </p>
                @endif
            </div>

            {{-- Explore --}}
            <nav aria-labelledby="footer-explore" class="min-w-0">
                <h2 id="footer-explore" class="{{ $heading }}">Explore</h2>
                <ul class="mt-2 text-sm font-medium text-app-text-2">
                    @foreach ($explore as $item)
                        <li><a href="{{ route($item['route']) }}" class="flex min-h-11 items-center rounded sm:min-h-0 sm:py-1 {{ $link }}">{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>

            {{-- Contact: the owner's details only --}}
            @if ($hasContact)
                <div class="min-w-0">
                    <h2 class="{{ $heading }}">Contact</h2>
                    <address class="mt-2 text-sm not-italic leading-5 text-app-text-2" data-footer-contact>
                        @if (filled($shop['address'] ?? null))
                            <p class="{{ $row }}"><x-ui.icon name="location_on" :size="18" class="{{ $icon }}" /><span class="min-w-0 whitespace-pre-line break-words"><span class="sr-only">Address: </span>{{ $shop['address'] }}</span></p>
                        @endif
                        @if (filled($shop['opening_hours'] ?? null))
                            <p class="{{ $row }}"><x-ui.icon name="schedule" :size="18" class="{{ $icon }}" /><span class="min-w-0 break-words"><span class="sr-only">Shop opening hours: </span>{{ $shop['opening_hours'] }}</span></p>
                        @endif
                        @if ($phone)
                            <a href="{{ \App\Support\PhilippineMobile::telHref($phone) }}" class="{{ $row }} {{ $link }}"><x-ui.icon name="call" :size="18" class="{{ $icon }}" /><span class="min-w-0"><span class="sr-only">Phone: </span>{{ \App\Support\PhilippineMobile::display($phone) }}</span></a>
                        @endif
                        @if (filled($shop['email'] ?? null))
                            <a href="mailto:{{ $shop['email'] }}" class="{{ $row }} {{ $link }}"><x-ui.icon name="mail" :size="18" class="{{ $icon }}" /><span class="min-w-0 break-all"><span class="sr-only">Email: </span>{{ $shop['email'] }}</span></a>
                        @endif
                        @if (filled($shop['facebook_url'] ?? null))
                            <a href="{{ $shop['facebook_url'] }}" target="_blank" rel="noopener noreferrer external" class="{{ $row }} {{ $link }}">
                                {{-- The Facebook mark (Material Symbols has none) --}}
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" class="{{ $icon }}" aria-hidden="true" data-facebook-icon><path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.43c0-3.01 1.79-4.67 4.53-4.67 1.31 0 2.69.24 2.69.24v2.95h-1.52c-1.49 0-1.95.93-1.95 1.87v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07Z"/></svg>
                                <span class="min-w-0">Jhunlea on Facebook<span class="sr-only"> (opens in a new tab)</span></span>
                            </a>
                        @endif
                    </address>
                </div>
            @endif
        </div>

        <div class="mt-8 flex flex-col gap-1 border-t border-app-border pt-4 text-xs text-app-text-2 sm:flex-row sm:items-center sm:justify-between">
            <p class="py-2 sm:py-0">&copy; {{ date('Y') }} Jhunlea Photography &amp; Printing</p>
            <a href="{{ route('terms') }}" class="inline-flex min-h-11 items-center self-start rounded font-semibold hover:text-brand focus-ring sm:min-h-0 sm:self-auto sm:py-1 dark:hover:text-[#ffb4ac]">Terms and privacy</a>
        </div>
    </div>
</footer>
