{{-- Shared by the create and edit pages. Needs $theme, $action, $method, $submitLabel, $packages and $selectedPackageIds. --}}
@php
    use App\Http\Requests\Owner\ThemeRequest;
    use App\Models\PackageImage;

    // Old input only counts when this form was the one submitted
    $hasOld = session()->hasOldInput();
    $published = $hasOld ? (bool) old('is_published') : $theme->is_published;
    $chosenPackages = array_map('intval', $hasOld ? (array) old('package_ids', []) : $selectedPackageIds);
    $slots = array_pad(array_values((array) ($hasOld ? old('color_swatches', []) : $theme->color_swatches)), ThemeRequest::MAX_SWATCHES, '');
    $swatchError = $errors->first('color_swatches') ?: $errors->first('color_swatches.*');
@endphp

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="lg:grid lg:grid-cols-12 lg:items-start lg:gap-8" x-on:input="sync()" x-on:change="sync()"
    x-data="themeForm({ name: @js(old('name', $theme->name)), colors: @js(array_map('strval', $slots)), imageUrl: @js($theme->imageUrl()) })">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="space-y-6 lg:col-span-7">
        <x-ui.card>
            <h2 class="font-display text-xl font-semibold">1. Theme details</h2>
            <div class="mt-5 space-y-5">
                <x-ui.input label="Theme name" name="name" :value="old('name', $theme->name)" :required="true" x-model="name" />
                <x-ui.textarea label="Description" name="description" hint="What this backdrop looks like and when it suits a shoot.">{{ old('description', $theme->description) }}</x-ui.textarea>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="font-display text-xl font-semibold">2. Color palette</h2>
            <p class="mt-1 text-sm text-app-text-2">Up to {{ ThemeRequest::MAX_SWATCHES }} colors. Pick one, or type it as #RRGGBB. Empty boxes are ignored.</p>
            <div class="mt-5 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                @foreach ($slots as $i => $color)
                    <div class="flex items-center gap-2">
                        {{-- The picker has no name: it only fills the hex box next to it, which is what is submitted --}}
                        <input type="color" aria-label="Pick color {{ $i + 1 }}" value="#FFFFFF" x-bind:value="valid(colors[{{ $i }}]) ? colors[{{ $i }}] : '#FFFFFF'"
                            x-on:input="colors[{{ $i }}] = $event.target.value.toUpperCase()"
                            class="size-11 shrink-0 cursor-pointer rounded-control border border-neutral-200 bg-app-card p-1 focus-ring dark:border-neutral-700">
                        <x-ui.input label="Color {{ $i + 1 }}" name="color_swatches[]" :value="$color" maxlength="7" placeholder="#C62828" autocomplete="off" spellcheck="false" x-model="colors[{{ $i }}]" class="font-mono uppercase" />
                    </div>
                @endforeach
            </div>
            @if ($swatchError)
                <p class="mt-2 flex items-start gap-1 text-sm text-status-red"><x-ui.icon name="error" :size="16" class="mt-0.5" /> <span>{{ $swatchError }}</span></p>
            @endif
        </x-ui.card>

        <x-ui.card>
            <h2 class="font-display text-xl font-semibold">3. Photo</h2>
            <div class="mt-5 space-y-4" x-data="{ file: '' }">
                @if ($theme->imageUrl())
                    <div class="flex items-center gap-4">
                        <img src="{{ $theme->imageUrl() }}" alt="Current photo of {{ $theme->name }}" class="h-20 w-28 rounded-control object-cover">
                        <x-ui.checkbox name="remove_image" value="1" label="Remove this photo" :checked="old('remove_image')" />
                    </div>
                @endif
                <x-ui.field id="theme-image" :label="$theme->imageUrl() ? 'Replace the photo' : 'Add a photo'" :error="$errors->first('image')"
                    :hint="'JPG, PNG or WebP, up to '.(PackageImage::MAX_SIZE_KB / 1024).' MB. Optional.'">
                    <input id="theme-image" type="file" name="image" accept="image/jpeg,image/png,image/webp" x-on:change="file = $event.target.files[0]?.name ?? ''"
                        @if ($errors->has('image')) aria-invalid="true" aria-describedby="theme-image-error" @endif
                        class="block w-full cursor-pointer rounded-control border border-neutral-200 bg-app-card text-sm file:mr-3 file:h-11 file:cursor-pointer file:border-0 file:bg-neutral-100 file:px-4 file:font-semibold file:text-app-text hover:file:bg-neutral-200 focus-ring dark:border-neutral-700 dark:file:bg-white/10 dark:hover:file:bg-white/20">
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="font-display text-xl font-semibold">4. Compatible packages</h2>
            @if ($packages->isEmpty())
                <p class="mt-3 text-sm text-app-text-2">No packages yet. Packages you add will show here so you can link this theme to them.</p>
            @else
                <fieldset class="mt-4">
                    <legend class="sr-only">Packages that offer this theme</legend>
                    <div class="grid gap-x-4 sm:grid-cols-2">
                        @foreach ($packages as $package)
                            <label class="flex min-h-11 cursor-pointer items-center gap-3 py-2 text-sm">
                                <input type="checkbox" class="ui-checkbox" name="package_ids[]" value="{{ $package->id }}" @checked(in_array($package->id, $chosenPackages, true))>
                                <span class="min-w-0">{{ $package->name }}@unless ($package->is_published) <span class="text-xs text-app-text-2">(not published)</span>@endunless</span>
                            </label>
                        @endforeach
                    </div>
                    @if ($errors->has('package_ids') || $errors->has('package_ids.*'))
                        <p class="mt-1 flex items-start gap-1 text-sm text-status-red"><x-ui.icon name="error" :size="16" class="mt-0.5" /> <span>{{ $errors->first('package_ids') ?: $errors->first('package_ids.*') }}</span></p>
                    @endif
                </fieldset>
            @endif
        </x-ui.card>

        <x-ui.card>
            <h2 class="font-display text-xl font-semibold">5. Publishing</h2>
            <div class="mt-4">
                <x-ui.checkbox name="is_published" value="1" label="Published (customers can pick this theme)" :checked="$published" />
            </div>
        </x-ui.card>

        <div class="flex flex-wrap items-center gap-3">
            @isset($deleteTarget)
                <x-ui.button type="button" variant="ghost" class="mr-auto text-status-red"
                    x-on:click="target = { name: {{ \Illuminate\Support\Js::from($deleteTarget->name) }}, action: {{ \Illuminate\Support\Js::from(route('owner.themes.destroy', $deleteTarget)) }} }; $dispatch('open-modal', 'delete-theme')">
                    <x-ui.icon name="delete" :size="18" /> Delete theme
                </x-ui.button>
            @endisset
            <x-ui.button :href="route('owner.themes.index')" variant="outline" class="max-sm:flex-1">Cancel</x-ui.button>
            <x-ui.button type="submit" class="max-sm:flex-1">{{ $submitLabel }}</x-ui.button>
        </div>
    </div>

    {{-- Live preview built only from this form's own fields (see themeForm in app.js).
         Decorative: aria-hidden, nothing focusable, no labels. Hidden below lg, where there is no room beside the form. --}}
    <aside class="hidden lg:col-span-5 lg:block" aria-hidden="true">
        <div class="sticky top-24 space-y-3">
            <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-app-text-2">
                <x-ui.icon name="palette" :size="18" class="text-brand" /> Customer view preview
            </p>

            <div class="overflow-hidden rounded-card border border-app-border bg-app-card shadow-[0_2px_8px_-2px_rgba(23,23,23,0.04),0_1px_4px_-1px_rgba(23,23,23,0.02)] transition-[transform,box-shadow] duration-300 ease-brand hover:-translate-y-1 hover:shadow-[0_12px_24px_-6px_rgba(23,23,23,0.08)] dark:shadow-none">
                <div class="relative aspect-[4/3] overflow-hidden">
                    <template x-if="imageUrl"><img x-bind:src="imageUrl" alt="" class="size-full object-cover"></template>
                    <template x-if="! imageUrl"><x-ui.image-placeholder icon="palette" class="size-full" /></template>
                    <span class="absolute bottom-3 left-3 flex -space-x-1.5">
                        <template x-for="(color, index) in swatches" x-bind:key="index">
                            <span class="size-8 rounded-full shadow-sm ring-2 ring-white dark:ring-neutral-900 transition-transform duration-300 hover:scale-110" x-bind:style="{ backgroundColor: color }"></span>
                        </template>
                    </span>
                </div>
                <div class="space-y-2 p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="break-words font-display text-[22px] font-medium leading-[30px]" x-bind:class="name.trim() ? '' : 'text-app-muted'" x-text="name.trim() || 'Theme name'"></h3>
                        <span class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold"
                            x-bind:class="published ? 'bg-status-green-bg text-status-green' : 'bg-status-gray-bg text-status-gray'">
                            <span class="size-1.5 rounded-full bg-current"></span>
                            <span x-text="published ? 'Published' : 'Not published'"></span>
                        </span>
                    </div>
                    <p class="line-clamp-3 break-words text-sm text-app-text-2" x-bind:class="description ? '' : 'text-app-muted'" x-text="description || 'The description appears here.'"></p>
                    <ul class="flex flex-wrap gap-2 pt-1">
                        <template x-for="(color, index) in swatches" x-bind:key="'code-' + index">
                            <li class="rounded bg-neutral-100 px-2 py-0.5 font-mono text-[11px] dark:bg-white/10" x-text="color"></li>
                        </template>
                    </ul>
                    <p x-show="! swatches.length" class="text-sm text-app-muted">Colors appear here.</p>
                </div>
            </div>
        </div>
    </aside>
</form>
