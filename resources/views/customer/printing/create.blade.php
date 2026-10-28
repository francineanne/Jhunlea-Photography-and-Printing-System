@extends('layouts.customer')

@section('title', 'Request printing | '.config('app.name'))

@php
    $steps = [
        1 => ['label' => 'Choose service', 'icon' => 'print'],
        2 => ['label' => 'Options and copies', 'icon' => 'tune'],
        3 => ['label' => 'Files and notes', 'icon' => 'cloud_upload'],
        4 => ['label' => 'Review and send', 'icon' => 'task_alt'],
    ];
    $hasOld = session()->hasOldInput();
    $oldService = (int) old('service');
    $optionErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'options'))->flatten()->unique();
    $fileErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'files'))->flatten()->unique();
    $allExtensions = $rules->pluck('extensions')->flatten()->unique()->values();
    $startPerPage = (bool) ($rules[$oldService]['perPage'] ?? false);
@endphp

@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('customer.dashboard') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">My dashboard</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">Request printing</li>
        </ol>
    </nav>

    <header class="max-w-2xl">
        <h1 class="font-display text-3xl font-semibold leading-tight sm:text-4xl">Request printing</h1>
        <p class="mt-2 text-app-text-2">Choose a service, set your options, add your files and send. The shop checks every request before it is accepted.</p>
    </header>

    @if (session('error'))
        <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg"><p>{{ session('error') }}</p></x-ui.notice>
    @endif

    @if ($services->isEmpty())
        <x-ui.card class="mt-8">
            <x-ui.empty-state icon="print" title="No printing services are open for requests yet"
                description="Please check back soon, or ask the shop what they can print for you.">
                <x-ui.button :href="route('customer.dashboard')" variant="outline">Back to my dashboard</x-ui.button>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <form method="POST" action="{{ route('customer.printing.store') }}" enctype="multipart/form-data" novalidate
            x-data="printingWizard(@js(['start' => $startStep, 'rules' => $rules, 'estimateUrl' => route('customer.printing.estimate'), 'maxFiles' => $maxFiles, 'maxTotalMb' => $maxTotalMb, 'maxQuantity' => $maxQuantity]))"
            x-on:change="sync()" x-on:submit="submit($event)">
            @csrf

            {{-- Stepper: four tiles and a track that fills as the steps advance (as in the design). Without scripts the tiles jump to the sections --}}
            <div class="mt-8 rounded-card border border-app-border bg-app-card p-2 shadow-[0_2px_8px_-2px_rgba(23,23,23,0.04),0_1px_4px_-1px_rgba(23,23,23,0.02)] dark:shadow-none sm:p-3">
                <ol class="grid grid-cols-2 gap-2 md:grid-cols-4">
                    @foreach ($steps as $number => $item)
                        <li>
                            <a href="#step-{{ $number }}" x-on:click.prevent="goTo({{ $number }})"
                                x-bind:aria-current="step === {{ $number }} ? 'step' : false"
                                x-bind:aria-disabled="{{ $number }} > reached ? 'true' : 'false'"
                                class="flex min-h-14 items-center gap-2.5 rounded-control p-2 transition-colors duration-[280ms] ease-brand focus-ring md:p-2.5"
                                x-bind:class="step === {{ $number }} ? 'bg-app-bg shadow-sm dark:bg-white/10' : ({{ $number }} > reached ? 'opacity-70' : 'hover:bg-black/5 dark:hover:bg-white/5')">
                                <span class="grid size-8 shrink-0 place-items-center rounded-full text-sm font-semibold transition-colors duration-[280ms] ease-brand"
                                    x-bind:class="{{ $number }} <= reached ? 'bg-brand text-white' : 'bg-neutral-200 text-app-text-2 dark:bg-white/10'">{{ $number }}</span>
                                <span class="min-w-0">
                                    <span class="block text-[11px] font-bold uppercase tracking-wider"
                                        x-bind:class="{{ $number }} <= reached ? 'text-brand' : 'text-app-text-2'">Step {{ $number }}</span>
                                    <span class="block truncate text-sm font-semibold">{{ $item['label'] }}</span>
                                </span>
                                <x-ui.icon :name="$item['icon']" :size="18" class="ml-auto hidden shrink-0 sm:block"
                                    x-bind:class="{{ $number }} <= reached ? 'text-brand' : 'text-app-text-2'" />
                            </a>
                        </li>
                    @endforeach
                </ol>
                <div class="mx-1 mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-200 dark:bg-white/10" aria-hidden="true">
                    <div class="h-full rounded-full bg-brand transition-[width] duration-500 ease-brand" style="width: {{ $startStep * 25 }}%" x-bind:style="`width: ${step * 25}%`"></div>
                </div>
            </div>

            <div class="mt-6 grid items-start gap-6 lg:grid-cols-12">
                <div class="min-w-0 space-y-6 lg:col-span-8">

                    {{-- Step 1: service --}}
                    <section id="step-1" aria-labelledby="step-1-title" x-show="step === 1"
                        @if ($startStep !== 1) data-js-hidden x-bind:data-js-hidden="false" @endif
                        class="scroll-mt-28 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none">
                        @include('customer.printing._step-header', ['number' => 1, 'title' => 'Choose a printing service', 'text' => 'Pick what you want printed. You set the details in the next step.', 'pill' => $services->count().' '.Str::plural('service', $services->count())])

                        @error('service')
                            <p class="mb-4 flex items-start gap-1 text-sm text-status-red" id="service-error"><x-ui.icon name="error" :size="16" class="mt-0.5" /><span>{{ $message }}</span></p>
                        @enderror

                        <fieldset>
                            <legend class="sr-only">Printing service</legend>
                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ($services as $service)
                                    <label class="group relative flex cursor-pointer flex-col rounded-card border border-app-border bg-neutral-50 p-4 transition-[background-color,border-color,box-shadow] duration-300 hover:bg-neutral-100 has-checked:border-brand has-checked:bg-app-card has-checked:shadow-sm has-checked:ring-2 has-checked:ring-brand has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand dark:bg-white/5 dark:hover:bg-white/10 dark:has-checked:bg-white/10">
                                        <input type="radio" name="service" value="{{ $service->id }}" class="peer sr-only" data-name="{{ $service->name }}"
                                            @checked($oldService === $service->id) @error('service') aria-describedby="service-error" @enderror>
                                        <span class="flex items-center justify-between gap-2">
                                            <x-ui.icon name="print" :size="24" class="text-app-text-2 transition-colors group-hover:text-brand group-has-checked:text-brand" />
                                            <span class="grid size-5 place-items-center rounded-full bg-neutral-200 text-white transition-colors group-has-checked:bg-brand dark:bg-white/15" aria-hidden="true">
                                                <x-ui.icon name="check" :size="14" class="opacity-0 transition-opacity group-has-checked:opacity-100" />
                                            </span>
                                        </span>
                                        <span class="mt-3 flex flex-wrap items-center gap-2">
                                            <span class="font-semibold">{{ $service->name }}</span>
                                            <span class="rounded bg-neutral-200/70 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-app-text-2 dark:bg-white/10">{{ $service->category }}</span>
                                        </span>
                                        @if ($service->description)
                                            <span class="mt-1 line-clamp-2 text-sm text-app-text-2">{{ $service->description }}</span>
                                        @endif
                                        <span class="mt-3 text-sm font-semibold tabular-nums">{{ $service->priceText() }}</span>
                                        <span class="mt-1 text-xs text-app-text-2">Files: <span class="font-mono uppercase">{{ implode(', ', $service->allowed_file_types ?? []) }}</span> · up to {{ intdiv($rules[$service->id]['maxKb'], 1024) }} MB</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        @include('customer.printing._step-nav', ['number' => 1])
                    </section>

                    {{-- Step 2: options, copies and pages --}}
                    <section id="step-2" aria-labelledby="step-2-title" x-show="step === 2"
                        @if ($startStep !== 2) data-js-hidden x-bind:data-js-hidden="false" @endif
                        class="scroll-mt-28 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none">
                        @include('customer.printing._step-header', ['number' => 2, 'title' => 'Options and copies', 'text' => 'Choose the options for your service and how many copies you need.', 'pill' => 'Estimate live'])

                        @if ($optionErrors->isNotEmpty())
                            <ul class="mb-4 space-y-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert">
                                @foreach ($optionErrors as $message)
                                    <li class="flex items-start gap-1"><x-ui.icon name="error" :size="16" class="mt-0.5" /><span>{{ $message }}</span></li>
                                @endforeach
                            </ul>
                        @endif

                        <p class="mb-4 text-sm text-app-text-2" x-show="false">Without scripts, the options of every service are listed below. Only the options under the service you chose in step 1 are used.</p>
                        <p class="mb-4 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5" x-cloak x-show="!serviceId">Choose a service in step 1 first.</p>

                        @foreach ($services as $service)
                            <fieldset class="mb-6 space-y-5" data-service-options="{{ $service->id }}"
                                x-show="serviceId === {{ $service->id }}" x-bind:disabled="serviceId !== {{ $service->id }}">
                                <legend class="mb-3 text-sm font-semibold text-app-text-2">Options for <span class="text-app-text">{{ $service->name }}</span></legend>

                                @php $byGroup = $service->options->groupBy(fn ($option) => $option->group->value); @endphp

                                @if ($byGroup->isEmpty())
                                    <p class="text-sm text-app-text-2">This service has no options to choose.</p>
                                @endif

                                @foreach ($groups as $group)
                                    @if ($byGroup->has($group->value))
                                        <fieldset>
                                            <legend class="mb-2 text-sm font-semibold">{{ $group->label() }}</legend>
                                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                @foreach ($byGroup[$group->value] as $index => $option)
                                                    @php
                                                        $field = "options.{$service->id}.{$group->value}";
                                                        $checked = $hasOld ? (int) old($field) === $option->id : $index === 0;
                                                    @endphp
                                                    <label class="group flex min-h-14 cursor-pointer items-start justify-between gap-3 rounded-control border border-app-border bg-neutral-50 p-3 transition-[background-color,border-color,box-shadow] duration-300 hover:bg-neutral-100 has-checked:border-brand has-checked:bg-app-card has-checked:ring-2 has-checked:ring-brand has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand dark:bg-white/5 dark:hover:bg-white/10 dark:has-checked:bg-white/10">
                                                        <span class="min-w-0">
                                                            <span class="block text-sm font-semibold">{{ $option->label }}</span>
                                                            <span class="mt-0.5 block font-mono text-xs text-app-text-2 group-has-checked:text-brand">{{ \App\Support\Money::modifier($option->price_modifier) }}</span>
                                                        </span>
                                                        <input type="radio" name="options[{{ $service->id }}][{{ $group->value }}]" value="{{ $option->id }}"
                                                            data-label="{{ $group->label() }}: {{ $option->label }}" @checked($checked)
                                                            class="mt-0.5 size-4 shrink-0 accent-brand">
                                                    </label>
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @endif
                                @endforeach
                            </fieldset>
                        @endforeach

                        <div class="grid gap-5 border-t border-app-border pt-5 md:grid-cols-2">
                            <x-ui.field id="quantity" label="Number of copies" :error="$errors->first('quantity')" :required="true"
                                hint="Each copy is one full set of your file.">
                                <div class="flex items-center gap-1 rounded-control border border-neutral-200 bg-app-card p-0.5 dark:border-neutral-700">
                                    <button type="button" x-cloak x-on:click="changeCopies(-1)" aria-label="One copy fewer" aria-controls="quantity"
                                        class="grid size-11 shrink-0 place-items-center rounded-[8px] bg-neutral-100 transition-colors hover:bg-neutral-200 focus-ring dark:bg-white/10 dark:hover:bg-white/15">
                                        <x-ui.icon name="remove" :size="18" />
                                    </button>
                                    <input id="quantity" type="number" name="quantity" value="{{ old('quantity', 1) }}" min="1" max="{{ $maxQuantity }}" step="1" inputmode="numeric" required
                                        x-ref="quantity" x-on:input="sync()"
                                        @if ($errors->has('quantity')) aria-invalid="true" aria-describedby="quantity-error" @else aria-describedby="quantity-hint" @endif
                                        class="h-11 min-w-0 flex-1 rounded-[8px] bg-transparent text-center text-lg font-semibold tabular-nums field-focus">
                                    <button type="button" x-cloak x-on:click="changeCopies(1)" aria-label="One copy more" aria-controls="quantity"
                                        class="grid size-11 shrink-0 place-items-center rounded-[8px] bg-neutral-100 transition-colors hover:bg-neutral-200 focus-ring dark:bg-white/10 dark:hover:bg-white/15">
                                        <x-ui.icon name="add" :size="18" />
                                    </button>
                                </div>
                            </x-ui.field>

                            <div x-show="perPage" @unless ($startPerPage) data-js-hidden x-bind:data-js-hidden="false" @endunless>
                                <x-ui.input id="pages" name="pages" type="number" label="Number of pages" min="1" :max="$maxPages" step="1" inputmode="numeric"
                                    value="{{ old('pages', 1) }}" x-on:input="sync()"
                                    hint="Estimate only. The shop confirms the final price after checking your file." />
                                <p class="mt-1 text-xs text-app-text-2" x-show="false">Only used for services priced per page.</p>
                            </div>
                        </div>

                        @include('customer.printing._step-nav', ['number' => 2])
                    </section>

                    {{-- Step 3: files and notes --}}
                    <section id="step-3" aria-labelledby="step-3-title" x-show="step === 3"
                        @if ($startStep !== 3) data-js-hidden x-bind:data-js-hidden="false" @endif
                        class="scroll-mt-28 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none">
                        @include('customer.printing._step-header', ['number' => 3, 'title' => 'Files and notes', 'text' => 'Add the files to print. They stay private: only you and the shop can see them.', 'pill' => 'Up to '.$maxFiles.' files · '.$maxTotalMb.' MB'])

                        @if ($fileErrors->isNotEmpty())
                            <ul class="mb-4 space-y-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert" id="files-error">
                                @foreach ($fileErrors as $message)
                                    <li class="flex items-start gap-1"><x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span class="min-w-0 break-words">{{ $message }}</span></li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($errors->any())
                            <x-ui.notice class="mb-4"><p>Files are not kept when something needs fixing. Please choose your files again before you send.</p></x-ui.notice>
                        @endif

                        <div class="flex flex-col items-center rounded-card border-2 border-dashed border-app-border bg-neutral-50 p-5 text-center transition-colors duration-300 hover:bg-neutral-100 dark:bg-white/5 dark:hover:bg-white/10 sm:p-8"
                            x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false" x-on:drop.prevent="dropFiles($event)"
                            x-bind:class="dragging ? '!border-brand !bg-brand/5' : ''">
                            <span class="grid size-14 place-items-center rounded-full bg-app-card text-brand shadow-sm" aria-hidden="true"><x-ui.icon name="upload_file" :size="30" /></span>
                            <p class="mt-3 font-semibold"><span x-cloak class="hidden sm:inline">Drag and drop your files here, or choose them below</span><span class="sm:hidden">Choose the files to print</span><span x-show="false" class="hidden sm:inline">Choose the files to print</span></p>
                            <p class="mt-1 max-w-md text-sm text-app-text-2" x-cloak x-text="acceptText()"></p>
                            <p class="mt-1 max-w-md text-sm text-app-text-2" x-show="false">Each service in step 1 lists the files it accepts and the largest file it takes. Up to {{ $maxFiles }} files and {{ $maxTotalMb }} MB in total per order.</p>
                            <label for="files" class="sr-only">Files to print</label>
                            <input id="files" type="file" name="files[]" multiple x-ref="files" x-on:change.stop="readFiles(); sync()"
                                accept="{{ $allExtensions->map(fn ($ext) => '.'.$ext)->join(',') }}" x-bind:accept="acceptList()"
                                @if ($fileErrors->isNotEmpty()) aria-invalid="true" aria-describedby="files-error" @endif
                                class="mt-4 block w-full max-w-md cursor-pointer rounded-control text-sm text-app-text-2 file:mr-3 file:h-11 file:cursor-pointer file:rounded-control file:border-0 file:bg-brand file:px-5 file:text-sm file:font-semibold file:text-white file:transition-colors hover:file:bg-brand-deep focus-ring">
                        </div>

                        <div class="mt-5" x-cloak x-show="files.length > 0">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Files to send</p>
                                <p class="font-mono text-xs" x-bind:class="overTotal() ? 'font-semibold text-status-red' : 'text-app-text-2'"><span x-text="files.length"></span> / {{ $maxFiles }} files · <span x-text="size(totalBytes())"></span> of {{ $maxTotalMb }} MB</p>
                            </div>
                            <p class="mt-2 flex items-start gap-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert" x-show="overTotal()">
                                <x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span x-text="totalProblem()"></span>
                            </p>
                            <ul class="mt-2 space-y-2">
                                <template x-for="(file, index) in files" x-bind:key="index + file.name">
                                    <li class="flex items-center gap-3 rounded-control p-3"
                                        x-bind:class="file.error ? 'border border-status-red/30 bg-status-red-bg' : 'bg-neutral-100 dark:bg-white/5'">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-lg" aria-hidden="true"
                                            x-bind:class="file.error ? 'bg-status-red text-white' : 'bg-app-card text-brand'">
                                            <x-ui.icon name="description" :size="20" x-show="!file.error" />
                                            <x-ui.icon name="warning" :size="20" x-show="file.error" />
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-semibold" x-text="file.name"></span>
                                            <span class="block text-xs" x-bind:class="file.error ? 'text-status-red' : 'text-app-text-2'" x-text="file.error || file.size"></span>
                                        </span>
                                        <button type="button" x-on:click="removeFile(index)" x-bind:aria-label="`Remove ${file.name}`"
                                            class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-lg px-3 text-sm font-semibold text-status-red transition-colors hover:bg-status-red-bg focus-ring sm:min-h-9">
                                            <x-ui.icon name="delete" :size="18" /><span class="hidden sm:inline">Remove</span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>

                        <div class="mt-6">
                            <x-ui.textarea id="notes" name="notes" label="Special instructions (optional)" maxlength="2000" rows="4" x-on:input="collect()"
                                placeholder="For example: staple the pages, or print page 3 in color."
                                hint="Up to 2,000 characters.">{{ old('notes') }}</x-ui.textarea>
                        </div>

                        @include('customer.printing._step-nav', ['number' => 3])
                    </section>

                    {{-- Step 4: review and send --}}
                    <section id="step-4" aria-labelledby="step-4-title" x-show="step === 4"
                        @if ($startStep !== 4) data-js-hidden x-bind:data-js-hidden="false" @endif
                        class="scroll-mt-28 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none">
                        @include('customer.printing._step-header', ['number' => 4, 'title' => 'Review and send', 'text' => 'Check your request. The shop reviews it before anything is printed.', 'pill' => 'Last step'])

                        <p class="text-sm text-app-text-2" x-show="false">Check your choices in the steps above, then send your order. Your estimate is shown on the next page.</p>

                        <div class="rounded-card bg-neutral-100 p-4 dark:bg-white/5 sm:p-5" x-cloak>
                            <p class="text-[11px] font-bold uppercase tracking-wider text-brand">Selected service</p>
                            <p class="mt-1 font-semibold" x-text="summary.service || 'No service chosen yet'"></p>
                            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Copies</dt>
                                    <dd class="mt-0.5 font-semibold tabular-nums" x-text="summary.quantity"></dd>
                                </div>
                                <div x-show="perPage">
                                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Pages</dt>
                                    <dd class="mt-0.5 font-semibold tabular-nums" x-text="summary.pages"></dd>
                                </div>
                                <div class="sm:col-span-2" x-show="summary.options.length">
                                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Options</dt>
                                    <dd class="mt-0.5">
                                        <ul class="space-y-0.5">
                                            <template x-for="label in summary.options" x-bind:key="label"><li x-text="label"></li></template>
                                        </ul>
                                    </dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Files</dt>
                                    <dd class="mt-0.5">
                                        <p x-show="!files.length" class="text-status-red">No files added yet.</p>
                                        <ul class="space-y-0.5">
                                            <template x-for="(file, index) in files" x-bind:key="index + file.name"><li class="truncate" x-text="file.name"></li></template>
                                        </ul>
                                    </dd>
                                </div>
                                <div class="sm:col-span-2" x-show="summary.notes">
                                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Special instructions</dt>
                                    <dd class="mt-0.5 whitespace-pre-line break-words" x-text="summary.notes"></dd>
                                </div>
                                <div class="border-t border-app-border pt-4 sm:col-span-2">
                                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Estimated price</dt>
                                    <dd class="mt-0.5 text-lg font-bold tabular-nums text-brand" x-text="estimateHeadline()"></dd>
                                </div>
                            </dl>
                        </div>

                        <x-ui.notice class="mt-4">
                            <p class="font-semibold">The shop checks your request first</p>
                            <p class="text-app-text-2">Sending this order does not mean it is accepted. The price shown is an estimate: the shop confirms the final price after checking your files.</p>
                        </x-ui.notice>

                        <x-ui.notice variant="payment" class="mt-4">
                            <p class="text-app-text-2">Nothing is charged online. The shop confirms the final price before you pay.</p>
                        </x-ui.notice>

                        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <x-ui.button variant="outline" x-cloak x-on:click="back()">
                                <x-ui.icon name="arrow_back" :size="18" />Back to files
                            </x-ui.button>
                            <button type="submit" x-bind:disabled="submitting" x-bind:aria-busy="submitting.toString()"
                                class="btn-sweep inline-flex h-11 items-center justify-center gap-2 rounded-control px-8 text-sm font-semibold text-white shadow-md hover:[transform:scale(1.01)] focus-ring active:[transform:scale(0.98)] disabled:pointer-events-none disabled:opacity-60">
                                <x-ui.aperture-spinner :size="18" x-cloak x-show="submitting" />
                                <span x-text="submitting ? 'Sending your order…' : 'Send printing order'">Send printing order</span>
                                <x-ui.icon name="send" :size="18" x-show="!submitting" />
                            </button>
                        </div>
                    </section>
                </div>

                {{-- Live estimate: the same PricingService result the order is saved with --}}
                <aside class="lg:sticky lg:top-28 lg:col-span-4" aria-labelledby="estimate-title">
                    <div class="rounded-card border border-app-border bg-app-card p-4 shadow-md sm:p-6 dark:shadow-none">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wider text-brand">Estimate</p>
                                <h2 id="estimate-title" class="font-display text-xl font-semibold">Live estimate</h2>
                            </div>
                            <span class="rounded-full bg-neutral-100 px-2.5 py-1 font-mono text-xs text-app-text-2 dark:bg-white/10">PHP (₱)</span>
                        </div>

                        <p class="mt-4 text-sm text-app-text-2" x-show="false">Your estimate is worked out when you send the order and shown on the next page.</p>

                        <div x-cloak aria-live="polite">
                            <p class="mt-4 text-sm text-app-text-2" x-show="estimateState === 'idle'">Choose a service to see an estimate.</p>
                            <p class="mt-4 flex items-center gap-2 text-sm text-app-text-2" x-show="estimateState === 'loading' && !estimate"><x-ui.aperture-spinner :size="18" /> Working out your estimate…</p>
                            <p class="mt-4 text-sm text-status-red" x-show="estimateState === 'invalid'" x-text="estimateProblem"></p>
                            <p class="mt-4 text-sm text-app-text-2" x-show="estimateState === 'offline'">The estimate is not available right now. It is worked out again when you send the order.</p>

                            <template x-if="estimate && (estimateState === 'ready' || estimateState === 'loading')">
                                <div class="transition-opacity duration-200" x-bind:class="estimateState === 'loading' ? 'opacity-60' : ''">
                                    <dl class="mt-4 space-y-2 text-sm">
                                        <template x-for="line in estimate.lines" x-bind:key="line.label">
                                            <div class="flex items-start justify-between gap-3">
                                                <dt class="text-app-text-2" x-text="line.label"></dt>
                                                <dd class="shrink-0 font-mono text-xs font-semibold tabular-nums" x-text="line.value"></dd>
                                            </div>
                                        </template>
                                    </dl>
                                    <div class="my-4 h-px bg-app-border"></div>
                                    <div class="flex items-baseline justify-between gap-3">
                                        <p class="text-sm text-app-text-2">Estimated total</p>
                                        <p class="text-right font-bold tabular-nums text-brand" x-bind:class="estimate.quoted ? 'text-base' : 'text-2xl'" x-text="estimate.total_text"></p>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <p class="mt-3 text-sm italic text-app-text-2">Estimate only. The shop confirms the final price after checking your file.</p>
                    </div>
                </aside>
            </div>
        </form>
    @endif
@endsection
