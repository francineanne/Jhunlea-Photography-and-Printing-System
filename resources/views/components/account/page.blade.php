@props(['user', 'area', 'counts' => null])

{{--
    The account page of the signed-in person (Phase 6C), the same for customers (/my/account) and the owner
    (/owner/account), after design/customer/profile_account_settings: a page heading with a live dot, a summary card
    with the initial, "member since" and (for a customer) their real request counts, links to the sections, then
    numbered sections for the profile, the password and the appearance (three preview cards). Each section is its own
    form with its own error bag, so a failed section never touches the others. Password fields are never filled back in.
--}}
@php
    use App\Enums\ColorScheme;

    $profileErrors = $errors->profile ?? new \Illuminate\Support\MessageBag;
    $passwordErrors = $errors->password ?? new \Illuminate\Support\MessageBag;
    $themeErrors = $errors->theme ?? new \Illuminate\Support\MessageBag;
    $currentTheme = $user->theme ?? ColorScheme::System;
    $chosenTheme = ColorScheme::tryFrom((string) old('theme')) ?? $currentTheme;
    $sections = [
        'profile' => ['Profile', 'person'],
        'security' => ['Password', 'lock'],
        'appearance' => ['Appearance', 'contrast'],
    ];
@endphp

<div>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>{{ $area === 'owner' ? 'Owner' : 'My account' }} / Account</p>
            <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Profile and account</h1>
            <p class="mt-2 max-w-2xl text-app-text-2">Your name and contact details, your password and how the pages look for you.</p>
        </div>
    </div>

    <div class="mt-6 grid items-start gap-6 lg:grid-cols-12">
        {{-- Summary and section links --}}
        <aside class="space-y-4 lg:sticky lg:top-24 lg:col-span-4 xl:col-span-3">
            <div class="rounded-card border border-app-border bg-app-card p-5 text-center shadow-sm transition-shadow hover:shadow-md dark:shadow-none">
                <span class="mx-auto grid size-20 place-items-center rounded-full bg-brand font-display text-3xl font-semibold text-white ring-4 ring-brand/15" aria-hidden="true">{{ Str::upper(Str::substr($user->name, 0, 1)) }}</span>
                <p class="mt-3 break-words font-display text-xl font-semibold">{{ $user->name }}</p>
                <p class="break-all text-sm text-app-text-2">{{ $user->email }}</p>
                <p class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-3 py-1 text-xs font-semibold text-app-text-2 dark:bg-white/10">
                    <x-ui.icon name="{{ $area === 'owner' ? 'storefront' : 'person' }}" :size="14" />{{ $area === 'owner' ? 'Shop owner' : 'Customer' }}
                </p>
                <p class="mt-3 font-mono text-xs text-app-text-2">Member since <x-ui.date :value="$user->created_at" format="M j, Y" /></p>
                @if ($counts !== null)
                    <dl class="mt-4 grid grid-cols-2 divide-x divide-app-border rounded-control bg-neutral-100 py-3 dark:bg-white/5">
                        <div>
                            <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Printing orders</dt>
                            <dd class="mt-0.5 text-xl font-bold tabular-nums">{{ $counts['orders'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Photoshoots</dt>
                            <dd class="mt-0.5 text-xl font-bold tabular-nums">{{ $counts['bookings'] }}</dd>
                        </div>
                    </dl>
                @endif
            </div>

            <nav aria-label="Account sections" class="rounded-card border border-app-border bg-app-card p-2 dark:shadow-none">
                <ul class="space-y-1">
                    @foreach ($sections as $key => [$label, $icon])
                        <li>
                            <a href="#{{ $key }}" class="group flex min-h-11 items-center justify-between gap-3 rounded-control px-3 text-sm font-semibold text-app-text-2 transition-all duration-200 hover:bg-neutral-100 hover:text-app-text focus-ring dark:hover:bg-white/10">
                                <span class="flex items-center gap-3"><x-ui.icon :name="$icon" :size="20" />{{ $label }}</span>
                                <x-ui.icon name="chevron_right" :size="18" class="transition-transform duration-200 group-hover:translate-x-0.5" />
                            </a>
                        </li>
                    @endforeach
                </ul>
                <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-app-border pt-1">
                    @csrf
                    <button type="submit" class="flex min-h-11 w-full items-center gap-3 rounded-control px-3 text-sm font-semibold text-status-red transition-colors hover:bg-status-red-bg focus-ring">
                        <x-ui.icon name="logout" :size="20" />Log out
                    </button>
                </form>
            </nav>
        </aside>

        <div class="min-w-0 space-y-6 lg:col-span-8 xl:col-span-9">
            {{-- 01 Profile --}}
            <section id="profile" class="scroll-mt-24" aria-labelledby="profile-title">
                <form method="POST" action="{{ route($area.'.account.profile') }}" class="rounded-card border border-app-border bg-app-card shadow-sm transition-shadow hover:shadow-md dark:shadow-none">
                    @csrf
                    <div class="p-4 sm:p-6">
                        <p class="font-mono text-[11px] font-semibold uppercase tracking-wider text-brand">01 / Profile</p>
                        <h2 id="profile-title" class="mt-1 font-display text-xl font-semibold">Profile information</h2>
                        <p class="mt-1 text-sm text-app-text-2">Used on your {{ $area === 'owner' ? 'account' : 'orders and bookings' }} and to sign in.</p>

                        @if ($profileErrors->any())
                            <x-ui.notice class="mt-4 !border-status-red/30 !bg-status-red-bg" role="alert">Please check the fields marked below. Nothing was saved.</x-ui.notice>
                        @endif

                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <x-ui.input label="Full name" name="name" icon="badge" autocomplete="name" maxlength="80" :required="true"
                                :value="old('name', $user->name)" :error="$profileErrors->first('name')" hint="2 to 80 characters." />
                            <x-ui.input label="Phone number (optional)" name="phone" type="tel" icon="call" autocomplete="tel"
                                :value="old('phone', $user->phone)" :error="$profileErrors->first('phone')" hint="So the shop can reach you. For example 0917 123 4567." />
                            <div class="md:col-span-2">
                                <x-ui.input label="Email address" name="email" type="email" icon="mail" autocomplete="email" maxlength="255" :required="true"
                                    :value="old('email', $user->email)" :error="$profileErrors->first('email')" hint="You sign in with this address." />
                            </div>
                            <div class="md:col-span-2">
                                <x-ui.input label="Current password (only to change your email)" name="current_password" type="password" icon="lock" autocomplete="current-password"
                                    :error="$profileErrors->first('current_password')" hint="Needed only when you change your email address. A new email is used to sign in from then on." />
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-col gap-3 rounded-b-card border-t border-app-border bg-neutral-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:bg-white/5">
                        <p class="flex items-start gap-2 text-xs text-app-text-2"><x-ui.icon name="info" :size="16" class="mt-px shrink-0 text-brand" />Your role cannot be changed here.</p>
                        <x-ui.button type="submit" class="shrink-0"><x-ui.icon name="check" :size="18" />Save profile</x-ui.button>
                    </div>
                </form>
            </section>

            {{-- 02 Password --}}
            <section id="security" class="scroll-mt-24" aria-labelledby="security-title">
                <form method="POST" action="{{ route($area.'.account.password') }}" class="rounded-card border border-app-border bg-app-card shadow-sm transition-shadow hover:shadow-md dark:shadow-none">
                    @csrf
                    <div class="p-4 sm:p-6">
                        <p class="font-mono text-[11px] font-semibold uppercase tracking-wider text-brand">02 / Password</p>
                        <h2 id="security-title" class="mt-1 font-display text-xl font-semibold">Change your password</h2>
                        <p class="mt-1 text-sm text-app-text-2">After a change you stay signed in here, and every other device where you are signed in is signed out.</p>

                        @if ($passwordErrors->any())
                            <x-ui.notice class="mt-4 !border-status-red/30 !bg-status-red-bg" role="alert">Your password was not changed. Please check the fields marked below.</x-ui.notice>
                        @endif

                        <div class="mt-4 max-w-xl space-y-4" x-data="passwordStrength(@js(\App\Support\PasswordRules::all()))">
                            <x-ui.input label="Current password" name="current_password" type="password" icon="lock" autocomplete="current-password" :required="true"
                                id="current-password-security" :error="$passwordErrors->first('current_password')" />
                            <div>
                                <x-ui.input label="New password" name="password" type="password" icon="key" autocomplete="new-password" :required="true"
                                    :error="$passwordErrors->first('password')" :hint="\App\Support\PasswordRules::summary()" />
                                <x-ui.password-feedback mode="meter" />
                                <x-ui.password-feedback mode="checklist" />
                            </div>
                            <div>
                                <x-ui.input label="Confirm new password" name="password_confirmation" type="password" icon="key" autocomplete="new-password" :required="true"
                                    :error="$passwordErrors->first('password_confirmation')" />
                                <x-ui.password-feedback mode="match" />
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-col gap-3 rounded-b-card border-t border-app-border bg-neutral-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:bg-white/5">
                        <p class="flex items-start gap-2 text-xs text-app-text-2"><x-ui.icon name="devices" :size="16" class="mt-px shrink-0 text-brand" />Other devices will need the new password.</p>
                        <x-ui.button type="submit" variant="secondary" class="shrink-0"><x-ui.icon name="key" :size="18" />Update password</x-ui.button>
                    </div>
                </form>
            </section>

            {{-- 03 Appearance --}}
            <section id="appearance" class="scroll-mt-24" aria-labelledby="appearance-title">
                <form method="POST" action="{{ route($area.'.account.theme') }}" class="rounded-card border border-app-border bg-app-card shadow-sm transition-shadow hover:shadow-md dark:shadow-none"
                    x-data="{ choice: @js($chosenTheme->value) }" x-on:theme-changed.window="choice = $event.detail">
                    @csrf
                    <div class="p-4 sm:p-6">
                        <p class="font-mono text-[11px] font-semibold uppercase tracking-wider text-brand">03 / Appearance</p>
                        <h2 id="appearance-title" class="mt-1 font-display text-xl font-semibold">Appearance</h2>
                        <p class="mt-1 text-sm text-app-text-2">Saved to your account, so every device you sign in on uses it. The theme button at the top switches between light and dark too.</p>

                        @if ($themeErrors->any())
                            <x-ui.notice class="mt-4 !border-status-red/30 !bg-status-red-bg" role="alert">{{ $themeErrors->first('theme') }}</x-ui.notice>
                        @endif

                        <div class="mt-4 grid gap-3 sm:grid-cols-3" role="radiogroup" aria-labelledby="appearance-title">
                            @foreach (ColorScheme::cases() as $scheme)
                                <label class="group relative flex cursor-pointer flex-col gap-3 rounded-card border border-app-border bg-neutral-50 p-3 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md has-[:checked]:border-brand has-[:checked]:ring-2 has-[:checked]:ring-brand/30 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand dark:bg-white/5">
                                    <input type="radio" name="theme" value="{{ $scheme->value }}" class="sr-only" @checked($chosenTheme === $scheme)
                                        x-model="choice" x-on:change="window.applyTheme && window.applyTheme(choice)">
                                    {{-- A small picture of the page in that theme --}}
                                    <span class="block h-20 overflow-hidden rounded-lg border border-app-border" aria-hidden="true">
                                        @if ($scheme === ColorScheme::System)
                                            <span class="grid h-full grid-cols-2">
                                                <span class="space-y-1.5 bg-white p-2"><span class="block h-1.5 w-3/4 rounded-full bg-neutral-300"></span><span class="block h-1.5 w-1/2 rounded-full bg-neutral-200"></span><span class="mt-3 block h-2.5 w-8 rounded bg-brand"></span></span>
                                                <span class="space-y-1.5 bg-[#171717] p-2"><span class="block h-1.5 w-3/4 rounded-full bg-neutral-600"></span><span class="block h-1.5 w-1/2 rounded-full bg-neutral-700"></span><span class="mt-3 block h-2.5 w-8 rounded bg-brand"></span></span>
                                            </span>
                                        @else
                                            @php($isDark = $scheme === ColorScheme::Dark)
                                            <span @class(['block h-full space-y-1.5 p-2', 'bg-white' => ! $isDark, 'bg-[#171717]' => $isDark])>
                                                <span @class(['block h-1.5 w-3/4 rounded-full', 'bg-neutral-300' => ! $isDark, 'bg-neutral-600' => $isDark])></span>
                                                <span @class(['block h-1.5 w-1/2 rounded-full', 'bg-neutral-200' => ! $isDark, 'bg-neutral-700' => $isDark])></span>
                                                <span class="mt-3 block h-2.5 w-10 rounded bg-brand"></span>
                                            </span>
                                        @endif
                                    </span>
                                    <span class="flex items-start justify-between gap-2">
                                        <span>
                                            <span class="block font-semibold">{{ $scheme->label() }}</span>
                                            <span class="block text-xs text-app-text-2">{{ $scheme->description() }}</span>
                                        </span>
                                        <span class="grid size-5 shrink-0 place-items-center rounded-full border border-app-border text-transparent transition-colors group-has-[:checked]:border-brand group-has-[:checked]:text-white group-has-[:checked]:bg-brand" aria-hidden="true"><x-ui.icon name="check" :size="14" /></span>
                                    </span>
                                    @if ($currentTheme === $scheme)
                                        <span class="font-mono text-[10px] font-semibold uppercase tracking-wider text-brand">Saved</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex flex-col gap-3 rounded-b-card border-t border-app-border bg-neutral-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:bg-white/5">
                        <p class="flex items-start gap-2 text-xs text-app-text-2"><x-ui.icon name="visibility" :size="16" class="mt-px shrink-0 text-brand" />Choosing one shows it at once; save to keep it.</p>
                        <x-ui.button type="submit" variant="outline" class="shrink-0"><x-ui.icon name="palette" :size="18" />Save appearance</x-ui.button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</div>
