{{-- Full-screen dark loader from design/login/aperture_page_loader (always dark, in both themes). --}}
{{-- Visible from first paint; the script below runs before it is painted and hides it when already seen this session. --}}
{{-- The fade is plain CSS, so a throttled background tab can never leave the overlay stuck on screen. --}}
<noscript><style>.page-loader { display: none !important; }</style></noscript>
<script>
    try { if (sessionStorage.getItem('jhunlea-loader-seen')) document.documentElement.classList.add('loader-seen'); } catch (e) {}
</script>

<div x-data="uiPageLoader" x-on:replay-loader.window="replay()" x-bind:aria-hidden="!show" x-bind:inert="!show"
    x-bind:class="show || 'pointer-events-none invisible opacity-0'"
    class="page-loader fixed inset-0 z-[100] overflow-y-auto bg-[#171717] text-neutral-100 transition-[opacity,visibility] duration-[280ms] ease-brand"
    role="status" aria-label="Loading page">
    <span class="sr-only">Loading page</span>
    {{-- Studio vignette with a soft red lens glow --}}
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_center,rgba(198,40,40,0.14)_0%,rgba(23,23,23,0.85)_55%,rgba(10,10,10,0.98)_100%)]" aria-hidden="true"></div>

    {{-- Viewfinder corner marks --}}
    <div class="pointer-events-none absolute left-4 top-4 opacity-40 sm:left-6 sm:top-6" aria-hidden="true"><div class="h-px w-6 bg-neutral-300"></div><div class="h-6 w-px bg-neutral-300"></div></div>
    <div class="pointer-events-none absolute right-4 top-4 flex flex-col items-end opacity-40 sm:right-6 sm:top-6" aria-hidden="true"><div class="h-px w-6 bg-neutral-300"></div><div class="h-6 w-px bg-neutral-300"></div></div>
    <div class="pointer-events-none absolute bottom-4 left-4 flex flex-col-reverse opacity-40 sm:bottom-6 sm:left-6" aria-hidden="true"><div class="h-px w-6 bg-neutral-300"></div><div class="h-6 w-px bg-neutral-300"></div></div>
    <div class="pointer-events-none absolute bottom-4 right-4 flex flex-col-reverse items-end opacity-40 sm:bottom-6 sm:right-6" aria-hidden="true"><div class="h-px w-6 bg-neutral-300"></div><div class="h-6 w-px bg-neutral-300"></div></div>

    <div class="relative flex min-h-full flex-col px-6 py-6 sm:px-10">
        <div class="flex items-center gap-3 border-b border-white/10 pb-4 pl-6 font-mono text-[13px] font-medium uppercase tracking-wider">
            <span class="inline-flex size-2.5 animate-pulse rounded-full bg-brand shadow-[0_0_8px_#c62828]" aria-hidden="true"></span>
            <span>Loading</span>
        </div>

        <div class="flex flex-1 flex-col items-center justify-center py-8">
            <div class="relative flex items-center justify-center">
                <div class="pointer-events-none absolute size-72 rounded-full bg-brand/20 blur-3xl" aria-hidden="true"></div>
                <div class="relative rounded-full bg-[#1b1c1c]/40 p-3 backdrop-blur-md">
                    <x-ui.aperture class="size-52 sm:size-64" />
                </div>
            </div>

            <div class="mt-8 flex flex-col items-center text-center">
                <p class="pl-[0.32em] text-[28px] font-extrabold uppercase leading-[44px] tracking-[0.32em] text-white sm:text-[36px]">Jhunlea</p>
                <p class="mt-2 text-[11px] font-bold uppercase tracking-[0.24em] text-neutral-300">Photography &amp; Printing</p>
            </div>

            <div class="mt-8 flex w-full max-w-80 flex-col items-center">
                <div class="mb-2.5 flex w-full items-center justify-between gap-4 font-mono text-xs text-neutral-300">
                    <span class="truncate" aria-hidden="true" x-text="status">Preparing your page...</span>
                    <span class="font-bold text-[#ffb4ac]" aria-hidden="true" x-text="Math.round(pct) + '%'">0%</span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-[#1b1c1c] p-px">
                    <div class="h-full w-0 rounded-full bg-gradient-to-r from-[#a20513] to-brand shadow-[0_0_12px_#c62828] transition-[width] duration-700 ease-out"
                        x-bind:style="{ width: pct + '%' }"></div>
                </div>

                <div class="mt-6 flex items-center gap-2.5 rounded-full bg-[#1b1c1c]/60 px-4 py-2 text-neutral-300">
                    <x-ui.icon name="storefront" :size="16" class="shrink-0 text-brand" />
                    <span class="text-left font-mono text-[13px]">Payment is made face-to-face at Jhunlea Photography & Printing.</span>
                </div>
            </div>
        </div>
    </div>
</div>
