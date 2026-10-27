@props(['code', 'title', 'message'])

{{-- Used by the 403, 404, 405, 419 and 429 pages. The button goes to the signed-in user's own home, or to the public home for guests. --}}
{{-- rescue(): an error page must never throw itself (session store down, odd role value), so it falls back to the guest view --}}
@php
    $userHome = rescue(fn () => auth()->user()?->role->home(), null, false);
    $signedIn = $userHome !== null;
    $home = $userHome ?? url('/');

    $labels = ['403' => 'Access denied', '404' => 'Not found', '405' => 'Not allowed', '419' => 'Page expired', '429' => 'Too many requests'];
    $art = ['404' => 'camera', '403' => 'lock'][$code] ?? 'aperture';
@endphp

<div class="mx-auto w-full max-w-[1280px] px-4 py-10 sm:px-8 sm:py-16">
    <div class="relative overflow-hidden rounded-card border border-app-border bg-app-card p-6 shadow-sm sm:p-10 lg:p-14">
        <p class="pointer-events-none absolute -bottom-6 -right-4 select-none font-display text-[120px] font-bold leading-none tracking-tighter text-app-text opacity-[0.03] sm:-bottom-10 sm:-right-8 sm:text-[220px]" aria-hidden="true">{{ $code }}</p>

        <div class="relative flex flex-col items-center gap-10 lg:flex-row lg:gap-12">
            <div class="flex w-full justify-center lg:w-1/2">
                <x-ui.error-art :type="$art" />
            </div>

            <div class="flex w-full flex-col items-start gap-4 lg:w-1/2">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-status-red-bg px-3 py-1 text-brand dark:text-status-red">
                    <span class="size-2 animate-ping rounded-full bg-brand" aria-hidden="true"></span>
                    <span class="font-mono text-[13px] font-bold">{{ $code }}</span>
                    <span class="text-xs font-semibold uppercase tracking-wider">{{ $labels[$code] ?? 'Error' }}</span>
                </span>

                <h1 class="font-display text-[28px] font-semibold leading-9 tracking-tight sm:text-[40px] sm:leading-[48px]">{{ $title }}</h1>
                <p class="max-w-lg leading-relaxed text-app-text-2">{{ $message }}</p>

                <x-ui.button :href="$home" class="group mt-2">
                    <x-ui.icon :name="$signedIn ? 'dashboard' : 'home'" :size="20" />
                    {{ $signedIn ? 'Go to my dashboard' : 'Go back home' }}
                </x-ui.button>
            </div>
        </div>
    </div>
</div>
