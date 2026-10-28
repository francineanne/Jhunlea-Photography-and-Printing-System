{{-- Shared by the create and edit pages. Needs $package, $action, $method, $submitLabel, $categories, $themes and $selectedThemeIds. --}}
@php
    // Old input only counts when this form was the one submitted (a gallery form on the same page also flashes input)
    $hasOld = session()->hasOldInput() && old('_form') === 'package';
    $published = $hasOld ? (bool) old('is_published') : $package->is_published;
    $featured = $hasOld ? (bool) old('is_featured') : $package->is_featured;
    $requiresConfirmation = $hasOld ? (bool) old('price_requires_confirmation') : $package->price_requires_confirmation;
    $chosenThemes = array_map('intval', $hasOld ? (array) old('theme_ids', []) : $selectedThemeIds);
    $oldInclusions = old('inclusions');
    $inclusionsText = $hasOld
        ? (is_array($oldInclusions) ? implode("\n", $oldInclusions) : (string) $oldInclusions)
        : implode("\n", $package->inclusions ?? []);
    $cover = $package->exists ? $package->images->firstWhere('is_cover', true) : null;
    $inclusionError = $errors->first('inclusions') ?: $errors->first('inclusions.*');
@endphp

<form method="POST" action="{{ $action }}" class="lg:grid lg:grid-cols-12 lg:items-start lg:gap-8" x-on:input="syncPreview()" x-on:change="syncPreview()"
    x-data="packageForm({ name: @js(old('name', $package->name)), slug: @js(old('slug', $package->slug)), locked: @js($package->exists || (filled(old('slug')) && old('slug') !== \Illuminate\Support\Str::slug((string) old('name')))), coverUrl: @js($cover?->url()), coverAlt: @js($cover?->alt_text ?: $package->name) })">
    @csrf
    <input type="hidden" name="_form" value="package">
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="space-y-6 lg:col-span-7">
        <x-ui.card>
            <h2 class="font-display text-xl font-semibold">1. General information</h2>
            <div class="mt-5 grid gap-x-6 gap-y-5 md:grid-cols-2">
                <x-ui.input label="Package name" name="name" :value="old('name', $package->name)" :required="true" x-model="name" x-on:input="fillSlug()" />
                <x-ui.input label="Web address (slug)" name="slug" :value="old('slug', $package->slug)" x-model="slug" x-on:input="locked = true"
                    hint="Used in links. It is filled in from the name; leave it as is unless you need a different one." />
                <x-ui.input label="Category" name="category" :value="old('category', $package->category)" :required="true" list="package-category-suggestions" autocomplete="off" />
                <datalist id="package-category-suggestions">
                    @foreach ($categories as $category)
                        <option value="{{ $category }}"></option>
                    @endforeach
                </datalist>
                <x-ui.input label="Display order" name="sort_order" type="number" min="0" step="1" :value="old('sort_order', $package->sort_order)" hint="Smaller numbers appear first." />
                <div class="md:col-span-2">
                    <x-ui.input label="Short summary" name="short_description" :value="old('short_description', $package->short_description)" :required="true" maxlength="200" hint="One line shown on the package cards." />
                </div>
                <div class="md:col-span-2">
                    <x-ui.textarea label="Full description" name="description" hint="Shown on the package page. Keep it clear.">{{ old('description', $package->description) }}</x-ui.textarea>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="font-display text-xl font-semibold">2. Pricing and session time</h2>
            <div class="mt-5 grid gap-x-6 gap-y-5 md:grid-cols-2">
                <x-ui.input label="Listed price (₱)" name="price" type="number" min="0" step="0.01" inputmode="decimal" :value="old('price', $package->price)"
                    hint='Leave empty to show "Price on request".' />
                <div class="self-end">
                    <x-ui.checkbox name="price_requires_confirmation" value="1" label="The final price is confirmed after review" :checked="$requiresConfirmation"
                        hint='Customers see "From" the listed price; you confirm the exact amount later.' />
                </div>
                <x-ui.input label="Session length (minutes)" name="duration_minutes" type="number" min="1" max="1440" step="1" :value="old('duration_minutes', $package->duration_minutes)" :required="true" />
                <x-ui.input label="Most people in the shoot" name="max_people" type="number" min="1" max="500" step="1" :value="old('max_people', $package->max_people)" :required="true" />
                <x-ui.input label="Edited digital photos" name="digital_outputs" type="number" min="0" max="10000" step="1" :value="old('digital_outputs', $package->digital_outputs)" hint="Optional. How many edited photos the customer receives." />
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="font-display text-xl font-semibold">3. What is included</h2>
            <div class="mt-5">
                <x-ui.textarea label="Inclusions" name="inclusions" :error="$inclusionError" rows="6"
                    hint="One item per line, up to {{ \App\Http\Requests\Owner\PackageRequest::MAX_INCLUSIONS }} items of {{ \App\Http\Requests\Owner\PackageRequest::MAX_INCLUSION_LENGTH }} characters.">{{ $inclusionsText }}</x-ui.textarea>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="font-display text-xl font-semibold">4. Themes and backdrops</h2>
            @if ($themes->isEmpty())
                <p class="mt-3 text-sm text-app-text-2">No themes yet. Themes you add will show here so you can link them to this package.</p>
            @else
                <fieldset class="mt-4">
                    <legend class="sr-only">Themes linked to this package</legend>
                    <div class="grid gap-x-4 sm:grid-cols-2">
                        @foreach ($themes as $theme)
                            <label class="flex min-h-11 cursor-pointer items-center gap-3 py-2 text-sm">
                                <input type="checkbox" class="ui-checkbox" name="theme_ids[]" value="{{ $theme->id }}" data-name="{{ $theme->name }}" data-swatches="{{ json_encode($theme->color_swatches) }}"
                                    @checked(in_array($theme->id, $chosenThemes, true))>
                                <span class="flex shrink-0 -space-x-1" aria-hidden="true">
                                    @foreach (array_slice($theme->color_swatches, 0, 4) as $color)
                                        <span class="size-4 rounded-full ring-2 ring-app-card" style="background-color: {{ $color }}"></span>
                                    @endforeach
                                </span>
                                <span class="min-w-0">{{ $theme->name }}@unless ($theme->is_published) <span class="text-xs text-app-text-2">(not published)</span>@endunless</span>
                            </label>
                        @endforeach
                    </div>
                    @if ($errors->has('theme_ids') || $errors->has('theme_ids.*'))
                        <p class="mt-1 flex items-start gap-1 text-sm text-status-red"><x-ui.icon name="error" :size="16" class="mt-0.5" /> <span>{{ $errors->first('theme_ids') ?: $errors->first('theme_ids.*') }}</span></p>
                    @endif
                </fieldset>
            @endif
        </x-ui.card>

        <x-ui.card>
            <h2 class="font-display text-xl font-semibold">5. Notes and publishing</h2>
            <div class="mt-5 space-y-2">
                <x-ui.textarea label="Terms and preparation notes" name="terms" hint="For example: what to bring or when to arrive.">{{ old('terms', $package->terms) }}</x-ui.textarea>
                <x-ui.checkbox name="is_published" value="1" label="Published (customers can see this package)" :checked="$published" />
                <x-ui.checkbox name="is_featured" value="1" label="Featured (shown first to customers)" :checked="$featured" />
            </div>
        </x-ui.card>

        <div class="flex flex-wrap items-center gap-3">
            @isset($deleteTarget)
                <x-ui.button type="button" variant="ghost" class="mr-auto text-status-red"
                    x-on:click="target = { name: {{ \Illuminate\Support\Js::from($deleteTarget->name) }}, action: {{ \Illuminate\Support\Js::from(route('owner.packages.destroy', $deleteTarget)) }} }; $dispatch('open-modal', 'delete-package')">
                    <x-ui.icon name="delete" :size="18" /> Delete package
                </x-ui.button>
            @endisset
            <x-ui.button :href="route('owner.packages.index')" variant="outline" class="max-sm:flex-1">Cancel</x-ui.button>
            <x-ui.button type="submit" class="max-sm:flex-1">{{ $submitLabel }}</x-ui.button>
        </div>
    </div>

    {{-- Live preview of the customer-facing card, built only from this form's own fields (see packageForm in app.js).
         Decorative: aria-hidden, nothing focusable, no labels. Hidden below lg, where there is no room beside the form. --}}
    <aside class="hidden lg:col-span-5 lg:block" aria-hidden="true">
        <div class="sticky top-24 space-y-3">
            <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-app-text-2">
                <x-ui.icon name="devices" :size="18" class="text-brand" /> Customer view preview
            </p>

            <div class="overflow-hidden rounded-card border border-app-border bg-app-card shadow-[0_2px_8px_-2px_rgba(23,23,23,0.04),0_1px_4px_-1px_rgba(23,23,23,0.02)] transition-[transform,translate,box-shadow] duration-300 ease-brand hover:-translate-y-1 hover:shadow-[0_12px_24px_-6px_rgba(23,23,23,0.08)] dark:shadow-none">
                <div class="relative aspect-[4/3] overflow-hidden">
                    <template x-if="coverUrl"><img x-bind:src="coverUrl" x-bind:alt="coverAlt" class="size-full object-cover"></template>
                    <template x-if="! coverUrl"><x-ui.image-placeholder class="size-full" /></template>
                    <span class="absolute left-3 top-3 rounded-full bg-brand px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-white shadow-sm" x-text="preview.category || 'Category'"></span>
                    <span x-show="preview.featured" class="absolute right-3 top-3 inline-flex items-center gap-1 rounded-full bg-white/90 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-brand shadow-sm">
                        <x-ui.icon name="star" :size="14" /> Featured
                    </span>
                </div>

                <div class="space-y-4 p-5 sm:p-6">
                    <div>
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="break-words font-display text-[22px] font-medium leading-[30px]" x-bind:class="name.trim() ? '' : 'text-app-muted'" x-text="name.trim() || 'Package name'"></h3>
                            <span class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold"
                                x-bind:class="preview.published ? 'bg-status-green-bg text-status-green' : 'bg-status-gray-bg text-status-gray'">
                                <span class="size-1.5 rounded-full bg-current"></span>
                                <span x-text="preview.published ? 'Published' : 'Not published'"></span>
                            </span>
                        </div>
                        <p class="mt-1 text-sm font-semibold tabular-nums" x-text="priceText()"></p>
                        <p class="mt-2 line-clamp-3 break-words text-sm text-app-text-2" x-bind:class="preview.shortDescription ? '' : 'text-app-muted'"
                            x-text="preview.shortDescription || 'The one-line summary appears here.'"></p>
                    </div>

                    <p class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-app-text-2">
                        <span class="inline-flex items-center gap-1.5"><x-ui.icon name="schedule" :size="16" /><span x-text="(preview.duration || '–') + ' mins'"></span></span>
                        <span class="inline-flex items-center gap-1.5"><x-ui.icon name="group" :size="16" /><span x-text="preview.people ? (preview.people == 1 ? '1 person' : 'Up to ' + preview.people + ' people') : 'People'"></span></span>
                    </p>

                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">What is included</p>
                        <ul class="mt-2 space-y-1.5 text-sm">
                            <template x-for="(item, index) in preview.inclusions.slice(0, 6)" x-bind:key="index">
                                <li class="flex items-start gap-2"><span class="material-symbols-outlined mt-0.5 text-[16px] text-brand">check_circle</span><span class="break-words" x-text="item"></span></li>
                            </template>
                        </ul>
                        <p x-show="preview.inclusions.length > 6" class="mt-1.5 text-xs text-app-text-2" x-text="'+ ' + (preview.inclusions.length - 6) + ' more'"></p>
                        <p x-show="! preview.inclusions.length" class="mt-2 text-sm text-app-muted">Inclusions appear here.</p>
                    </div>

                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Backdrops</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <template x-for="(theme, themeIndex) in preview.themes" x-bind:key="themeIndex">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 py-1 pl-1.5 pr-2.5 text-xs dark:bg-white/10">
                                    <span class="flex -space-x-1"><template x-for="(color, colorIndex) in theme.swatches.slice(0, 3)" x-bind:key="colorIndex"><span class="size-3.5 rounded-full ring-2 ring-white dark:ring-neutral-800" x-bind:style="{ backgroundColor: color }"></span></template></span>
                                    <span x-text="theme.name"></span>
                                </span>
                            </template>
                            <span x-show="! preview.themes.length" class="text-sm text-app-muted">No themes linked.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </aside>
</form>
