{{-- Shared by the create and edit pages. Needs $service, $action, $method and $submitLabel. --}}
@php
    // Old input only counts when this form was the one submitted (an option form on the same page also flashes input)
    $hasOld = session()->hasOldInput() && old('_form') === 'service';
    $checkedTypes = $hasOld ? (array) old('allowed_file_types', []) : ($service->allowed_file_types ?? []);
    $published = $hasOld ? (bool) old('is_published') : $service->is_published;
    $pricingValue = old('pricing_type', $service->pricing_type?->value);
@endphp

<form method="POST" action="{{ $action }}" class="lg:grid lg:grid-cols-12 lg:items-start lg:gap-8" x-on:input="syncPreview()" x-on:change="syncPreview()"
    x-data="serviceForm({ name: @js(old('name', $service->name)), slug: @js(old('slug', $service->slug)), locked: @js($service->exists || (filled(old('slug')) && old('slug') !== \Illuminate\Support\Str::slug((string) old('name')))) })">
    @csrf
    <input type="hidden" name="_form" value="service">
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="space-y-6 lg:col-span-7">
    <x-ui.card>
        <h2 class="font-display text-xl font-semibold">1. Basic details</h2>
        <div class="mt-5 grid gap-x-6 gap-y-5 md:grid-cols-2">
            <x-ui.input label="Name" name="name" :value="old('name', $service->name)" :required="true" x-model="name" x-on:input="fillSlug()" />
            <x-ui.input label="Web address (slug)" name="slug" :value="old('slug', $service->slug)" x-model="slug" x-on:input="locked = true"
                hint="Used in links. It is filled in from the name; leave it as is unless you need a different one." />
            <x-ui.input label="Category" name="category" :value="old('category', $service->category)" :required="true" list="category-suggestions" autocomplete="off" />
            <datalist id="category-suggestions">
                @foreach ($categories as $category)
                    <option value="{{ $category }}"></option>
                @endforeach
            </datalist>
            <x-ui.input label="Display order" name="sort_order" type="number" min="0" step="1" :value="old('sort_order', $service->sort_order)" hint="Smaller numbers appear first." />
            <div class="md:col-span-2">
                <x-ui.textarea label="Description" name="description" hint="Shown to customers. Keep it short and clear.">{{ old('description', $service->description) }}</x-ui.textarea>
            </div>
        </div>
    </x-ui.card>

    <x-ui.card>
        <h2 class="font-display text-xl font-semibold">2. Pricing model and base rate</h2>
        <div class="mt-5 grid gap-x-6 gap-y-5 md:grid-cols-2">
            <fieldset class="md:col-span-2">
                <legend class="mb-1.5 text-sm font-semibold">Pricing type <span class="text-brand" aria-hidden="true">*</span></legend>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach ($pricingTypes as $type)
                        <label class="relative flex min-h-11 cursor-pointer flex-col gap-1 rounded-card border border-app-border p-4 pr-10 text-sm transition-colors hover:border-neutral-400 has-[:checked]:border-brand has-[:checked]:bg-status-red-bg/40 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-brand">
                            <input type="radio" name="pricing_type" value="{{ $type->value }}" data-label="{{ $type->label() }}" class="peer sr-only" @checked($pricingValue === $type->value) required>
                            <span class="absolute right-3 top-4 size-4 rounded-full border-2 border-neutral-400 transition-colors peer-checked:border-brand peer-checked:bg-brand peer-checked:shadow-[inset_0_0_0_2px_var(--app-card)]" aria-hidden="true"></span>
                            <span class="font-semibold">{{ $type->label() }}</span>
                            <span class="text-xs text-app-text-2">{{ $type->hint() }}</span>
                        </label>
                    @endforeach
                </div>
                @error('pricing_type')
                    <p class="mt-1.5 flex items-start gap-1 text-sm text-status-red"><x-ui.icon name="error" :size="16" class="mt-0.5" /> <span>{{ $message }}</span></p>
                @enderror
            </fieldset>
            <x-ui.input label="Base price (₱)" name="base_price" type="number" min="0" step="0.01" inputmode="decimal"
                :value="old('base_price', $service->base_price)" hint="Required unless the pricing type is quotation." />
        </div>
    </x-ui.card>

    <x-ui.card>
        <h2 class="font-display text-xl font-semibold">3. Customer file rules</h2>
        <div class="mt-5 space-y-5">
            <fieldset>
                <legend class="mb-1.5 text-sm font-semibold">Allowed file types <span class="text-brand" aria-hidden="true">*</span></legend>
                <div class="grid grid-cols-2 gap-x-4 sm:grid-cols-3 md:grid-cols-6">
                    @foreach ($fileTypes as $type)
                        <x-ui.checkbox name="allowed_file_types[]" value="{{ $type->value }}" :label="$type->label()"
                            :checked="in_array($type->value, $checkedTypes, true)" />
                    @endforeach
                </div>
                @if ($fileTypeError = $errors->first('allowed_file_types') ?: $errors->first('allowed_file_types.*'))
                    <p class="mt-1 flex items-start gap-1 text-sm text-status-red"><x-ui.icon name="error" :size="16" class="mt-0.5" /> <span>{{ $fileTypeError }}</span></p>
                @endif
            </fieldset>

            <div class="max-w-md">
                <x-ui.input label="Largest file allowed (MB)" name="max_file_size_mb" type="number" min="1" max="{{ $maxFileSize }}" step="1"
                    :value="old('max_file_size_mb', $service->max_file_size_mb)" :required="true"
                    hint="From 1 to {{ $maxFileSize }} MB. This server currently accepts uploads of up to {{ $serverUploadLimit }}B; raise upload_max_filesize and post_max_size in php.ini before using a larger value." />
            </div>

            <x-ui.textarea label="Instructions for customers" name="instructions" hint="For example: how to prepare the file before sending it.">{{ old('instructions', $service->instructions) }}</x-ui.textarea>
        </div>
    </x-ui.card>

    <x-ui.card>
        <x-ui.checkbox name="is_published" value="1" label="Published (customers can see and request this service)" :checked="$published" />
    </x-ui.card>

    <div class="flex flex-wrap items-center gap-3">
        @isset($deleteTarget)
            <x-ui.button type="button" variant="ghost" class="mr-auto text-status-red"
                x-on:click="target = { name: {{ \Illuminate\Support\Js::from($deleteTarget->name) }}, action: {{ \Illuminate\Support\Js::from(route('owner.services.destroy', $deleteTarget)) }} }; $dispatch('open-modal', 'delete-service')">
                <x-ui.icon name="delete" :size="18" /> Delete service
            </x-ui.button>
        @endisset
        <x-ui.button :href="route('owner.services.index')" variant="outline" class="max-sm:flex-1">Cancel</x-ui.button>
        <x-ui.button type="submit" class="max-sm:flex-1">{{ $submitLabel }}</x-ui.button>
    </div>
    </div>

    {{-- Live preview of the customer-facing card, built only from this form's own fields (see serviceForm in app.js).
         Decorative: aria-hidden, nothing focusable, no labels. Hidden below lg, where there is no room beside the form. --}}
    <aside class="hidden lg:col-span-5 lg:block" aria-hidden="true">
        <div class="sticky top-24 space-y-3">
            <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-app-text-2">
                <x-ui.icon name="devices" :size="18" class="text-brand" /> Customer view preview
            </p>

            <div class="rounded-card border border-app-border bg-app-card p-5 shadow-[0_2px_8px_-2px_rgba(23,23,23,0.04),0_1px_4px_-1px_rgba(23,23,23,0.02)] transition-[transform,translate,box-shadow] duration-300 ease-brand hover:-translate-y-1 hover:shadow-[0_12px_24px_-6px_rgba(23,23,23,0.08)] dark:shadow-none sm:p-6">
                <div class="flex items-center justify-between gap-2">
                    <span class="rounded-full bg-brand px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-white" x-text="preview.category || 'Category'"></span>
                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold"
                        x-bind:class="preview.published ? 'bg-status-green-bg text-status-green' : 'bg-status-gray-bg text-status-gray'">
                        <span class="size-1.5 rounded-full bg-current"></span>
                        <span x-text="preview.published ? 'Published' : 'Not published'"></span>
                    </span>
                </div>

                <h3 class="mt-4 break-words font-display text-[22px] font-medium leading-[30px]"
                    x-bind:class="name.trim() ? '' : 'text-app-muted'" x-text="name.trim() || 'Service name'"></h3>
                <p class="mt-2 line-clamp-3 whitespace-pre-line break-words text-sm text-app-text-2"
                    x-bind:class="preview.description ? '' : 'text-app-muted'" x-text="preview.description || 'The description customers read appears here.'"></p>

                <div class="mt-5 border-t border-app-border pt-4">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Price</p>
                    <p class="mt-1 font-display text-2xl font-semibold tabular-nums"
                        x-bind:class="money(preview.price) ? '' : 'text-app-muted'"
                        x-text="money(preview.price) || (preview.pricing === 'quotation' ? 'Quoted after review' : 'Base price')"></p>
                    <p class="text-sm text-app-text-2" x-text="preview.pricingLabel || 'Pricing type'"></p>
                </div>

                <div class="mt-5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Files accepted</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <template x-for="type in preview.types" x-bind:key="type">
                            <span class="rounded bg-neutral-100 px-2 py-0.5 font-mono text-[11px] uppercase dark:bg-white/10" x-text="type"></span>
                        </template>
                        <span x-show="! preview.types.length" class="text-sm text-app-muted">File types</span>
                    </div>
                </div>
            </div>
        </div>
    </aside>
</form>
