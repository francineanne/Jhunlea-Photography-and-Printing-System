{{-- Options editor on the edit page. Needs $service, $optionsByGroup and $groups, and sits inside the page's x-data (remove modal target). --}}
@php
    $newBag = 'new_option';
    $newHasErrors = $errors->hasBag($newBag);
    $newErrors = $errors->getBag($newBag);
@endphp

<section id="options" aria-labelledby="options-heading" class="scroll-mt-24">
    <h2 id="options-heading" class="font-display text-2xl font-semibold">4. Options</h2>
    <p class="mt-1 text-sm text-app-text-2">What customers can choose for this service, such as paper size or finish. The price is added to the base price.</p>

    <x-ui.card class="mt-5">
        <h3 class="font-semibold">Add an option</h3>
        <form method="POST" action="{{ route('owner.services.options.store', $service) }}" class="mt-4 grid gap-x-4 gap-y-4 md:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_minmax(0,0.8fr)_auto] lg:items-start">
            @csrf
            <x-ui.select label="Group" name="group" :error="$newErrors->first('group')">
                @foreach ($groups as $group)
                    <option value="{{ $group->value }}" @selected($newHasErrors && old('group') === $group->value)>{{ $group->label() }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="Label" name="label" :value="$newHasErrors ? old('label') : ''" :error="$newErrors->first('label')" placeholder="For example: A4 (210 x 297 mm)" />
            <x-ui.input label="Extra price (₱)" name="price_modifier" type="number" min="0" step="0.01" inputmode="decimal"
                :value="$newHasErrors ? old('price_modifier') : '0.00'" :error="$newErrors->first('price_modifier')" />
            <div class="lg:pt-7">
                <x-ui.checkbox name="is_active" value="1" label="Active" :checked="$newHasErrors ? (bool) old('is_active') : true" />
            </div>
            <div class="md:col-span-2 lg:col-span-4">
                <x-ui.button type="submit"><x-ui.icon name="add" :size="18" /> Add option</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="mt-6 space-y-6">
        @foreach ($groups as $group)
            @continue(! $optionsByGroup->has($group->value))

            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-app-text-2">{{ $group->label() }}</h3>
                <ul class="mt-2 space-y-3">
                    @foreach ($optionsByGroup[$group->value] as $option)
                        @php
                            $bag = 'option_'.$option->id;
                            $has = $errors->hasBag($bag);
                            $bagErrors = $errors->getBag($bag);
                        @endphp
                        <li>
                            <x-ui.card :padded="false" class="p-4">
                                <form method="POST" action="{{ route('owner.services.options.update', [$service, $option]) }}"
                                    class="grid gap-x-4 gap-y-4 md:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_minmax(0,0.8fr)_auto] lg:items-start">
                                    @csrf
                                    @method('PUT')
                                    <x-ui.select label="Group" name="group" :error="$bagErrors->first('group')">
                                        @foreach ($groups as $choice)
                                            <option value="{{ $choice->value }}" @selected(($has ? old('group') : $option->group->value) === $choice->value)>{{ $choice->label() }}</option>
                                        @endforeach
                                    </x-ui.select>
                                    <x-ui.input label="Label" name="label" :value="$has ? old('label') : $option->label" :error="$bagErrors->first('label')" />
                                    <x-ui.input label="Extra price (₱)" name="price_modifier" type="number" min="0" step="0.01" inputmode="decimal"
                                        :value="$has ? old('price_modifier') : $option->price_modifier" :error="$bagErrors->first('price_modifier')" />
                                    <div class="lg:pt-7">
                                        <x-ui.checkbox name="is_active" value="1" label="Active" :checked="$has ? (bool) old('is_active') : $option->is_active" />
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 md:col-span-2 lg:col-span-4">
                                        <x-ui.button type="submit" size="sm">Save option</x-ui.button>
                                        <x-ui.button type="button" variant="ghost" size="sm" class="text-status-red"
                                            x-on:click="target = { name: {{ \Illuminate\Support\Js::from($option->label) }}, action: {{ \Illuminate\Support\Js::from(route('owner.services.options.destroy', [$service, $option])) }} }; $dispatch('open-modal', 'remove-option')">
                                            <x-ui.icon name="delete" :size="18" /> Remove<span class="sr-only"> {{ $option->label }}</span>
                                        </x-ui.button>
                                        <span class="text-sm text-app-text-2">{{ \App\Support\Money::modifier($option->price_modifier) }}</span>
                                    </div>
                                </form>
                            </x-ui.card>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach

        @if ($optionsByGroup->isEmpty())
            <x-ui.card>
                <x-ui.empty-state icon="tune" title="No options yet" description="Add the first option above, for example a paper size." />
            </x-ui.card>
        @endif
    </div>
</section>
