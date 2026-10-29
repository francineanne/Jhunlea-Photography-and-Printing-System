@props(['user', 'tight' => false])

{{-- tight: the name next to the avatar hides from 1280 to 1535px, where a long top navigation needs the room (it stays in the menu and the label) --}}

<div x-data="{ open: false }" x-on:keydown.escape="if (open) { open = false; $refs.button.focus() }"
    x-on:click.outside="open = false" x-on:focusout="if ($event.relatedTarget && ! $el.contains($event.relatedTarget)) open = false" class="relative">
    <button x-ref="button" type="button" aria-haspopup="menu" x-bind:aria-expanded="open" aria-label="Account menu, {{ $user->name }}"
        x-on:click="open = ! open; if (open) $nextTick(() => $refs.menu.querySelector('button')?.focus())"
        class="flex min-h-11 items-center gap-2 rounded-control border border-app-border bg-app-card py-1 pl-1.5 pr-2.5 focus-ring">
        <span class="grid size-8 place-items-center rounded-full bg-brand text-sm font-semibold text-white" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($user->name, 0, 1)) }}</span>
        <span @class(['hidden max-w-[10rem] truncate text-sm font-semibold sm:inline', 'xl:hidden 2xl:inline' => $tight])>{{ $user->name }}</span>
        {{-- Wrapped because the icon font's own display rule would beat a `hidden` utility put on the icon itself --}}
        <span class="hidden text-app-text-2 sm:inline-flex"><x-ui.icon name="expand_more" :size="18" /></span>
    </button>

    <div x-ref="menu" x-show="open" x-cloak x-transition.opacity.duration.150ms role="menu" aria-label="Account"
        class="absolute right-0 top-full z-50 mt-2 w-64 max-w-[calc(100vw-2rem)] rounded-card border border-app-border bg-app-card p-2 text-app-text shadow-xl">
        <div class="border-b border-app-border px-3 pb-2 pt-1">
            <p class="truncate text-sm font-semibold">{{ $user->name }}</p>
            <p class="truncate text-xs text-app-text-2">{{ $user->email }}</p>
        </div>
        <form method="POST" action="{{ route('logout') }}" class="mt-2">
            @csrf
            <button type="submit" role="menuitem"
                class="flex min-h-11 w-full items-center gap-3 rounded-control px-3 text-sm font-semibold hover:bg-black/5 focus-ring dark:hover:bg-white/10">
                <x-ui.icon name="logout" :size="20" /> Log out
            </button>
        </form>
    </div>
</div>
