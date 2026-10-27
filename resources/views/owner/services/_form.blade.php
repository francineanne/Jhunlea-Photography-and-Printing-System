{{-- Shared by the create and edit pages. Needs $service, $action, $method and $submitLabel. --}}
@php
    // Old input only counts when this form was the one submitted (an option form on the same page also flashes input)
    $hasOld = session()->hasOldInput() && old('_form') === 'service';
    $checkedTypes = $hasOld ? (array) old('allowed_file_types', []) : ($service->allowed_file_types ?? []);
    $published = $hasOld ? (bool) old('is_published') : $service->is_published;
    $pricingValue = old('pricing_type', $service->pricing_type?->value);
@endphp

<form method="POST" action="{{ $action }}" class="space-y-6"
    x-data="serviceForm({ name: @js(old('name', $service->name)), slug: @js(old('slug', $service->slug)), locked: @js($service->exists || (filled(old('slug')) && old('slug') !== \Illuminate\Support\Str::slug((string) old('name')))) })">
    @csrf
    <input type="hidden" name="_form" value="service">
    @if ($method !== 'POST')
        @method($method)
    @endif

    <x-ui.card>
        <h2 class="font-display text-xl font-semibold">Details</h2>
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
        <h2 class="font-display text-xl font-semibold">Pricing</h2>
        <div class="mt-5 grid gap-x-6 gap-y-5 md:grid-cols-2">
            <x-ui.select label="Pricing type" name="pricing_type" :required="true"
                hint="Per page or per copy: the base price is charged each. Quotation: the owner sets the price after reviewing the request.">
                @foreach ($pricingTypes as $type)
                    <option value="{{ $type->value }}" @selected($pricingValue === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="Base price (₱)" name="base_price" type="number" min="0" step="0.01" inputmode="decimal"
                :value="old('base_price', $service->base_price)" hint="Required unless the pricing type is quotation." />
        </div>
    </x-ui.card>

    <x-ui.card>
        <h2 class="font-display text-xl font-semibold">Customer files</h2>
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

    <div class="flex flex-wrap gap-3">
        <x-ui.button type="submit">{{ $submitLabel }}</x-ui.button>
        <x-ui.button :href="route('owner.services.index')" variant="outline">Cancel</x-ui.button>
    </div>
</form>
