@extends('layouts.app')

@section('title', 'UI kit')

@section('content')
    <x-ui.page-loader />
    <x-ui.toast />

    <span id="ui-kit-top"></span>

    <header class="sticky top-0 z-40 border-b border-app-border bg-app-bg/90 backdrop-blur-[12px]">
        <div class="mx-auto flex max-w-[1280px] items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <x-ui.logo />
            <div class="flex items-center gap-2">
                <span class="hidden font-mono text-xs text-app-text-2 sm:inline">UI kit (local only)</span>
                <x-ui.theme-toggle />
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-[1280px] space-y-12 px-4 py-8 sm:px-6 sm:py-10">

        {{-- Logo and icons --}}
        <section class="space-y-4" aria-labelledby="s-logo">
            <h2 id="s-logo" class="font-display text-2xl font-semibold">Logo and icon</h2>
            <x-ui.card>
                <div class="flex flex-wrap items-end gap-x-10 gap-y-6">
                    <x-ui.logo />
                    <x-ui.logo size="lg" />
                    <div class="flex items-center gap-3 text-app-text-2">
                        <x-ui.icon name="photo_camera" :size="16" />
                        <x-ui.icon name="print" :size="24" />
                        <x-ui.icon name="event" :size="32" class="text-brand" />
                        <x-ui.icon name="check_circle" :size="24" label="Done" />
                    </div>
                </div>
            </x-ui.card>
        </section>

        {{-- Buttons --}}
        <section class="space-y-4" aria-labelledby="s-button">
            <h2 id="s-button" class="font-display text-2xl font-semibold">Button</h2>
            <x-ui.card><div class="space-y-6">
                @foreach (['md' => 'Default (44px)', 'sm' => 'Compact (36px on tablet and up, 44px on phones)'] as $size => $caption)
                    <div>
                        <p class="mb-3 text-sm font-semibold text-app-text-2">{{ $caption }}</p>
                        <div class="flex flex-wrap gap-3">
                            @foreach (['primary', 'secondary', 'outline', 'ghost', 'danger'] as $variant)
                                <x-ui.button :variant="$variant" :size="$size">{{ ucfirst($variant) }}</x-ui.button>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div>
                    <p class="mb-3 text-sm font-semibold text-app-text-2">Loading</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach (['primary', 'secondary', 'outline', 'ghost', 'danger'] as $variant)
                            <x-ui.button :variant="$variant" :loading="true">Saving</x-ui.button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <p class="mb-3 text-sm font-semibold text-app-text-2">Disabled</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach (['primary', 'secondary', 'outline', 'ghost', 'danger'] as $variant)
                            <x-ui.button :variant="$variant" :disabled="true">Disabled</x-ui.button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <p class="mb-3 text-sm font-semibold text-app-text-2">As a link, with an icon</p>
                    <div class="flex flex-wrap gap-3">
                        <x-ui.button href="#ui-kit-top"><x-ui.icon name="arrow_upward" :size="18" /> Back to top (link)</x-ui.button>
                        <x-ui.button href="#ui-kit-top" variant="outline" :disabled="true">Disabled link</x-ui.button>
                    </div>
                </div>
            </div></x-ui.card>
        </section>

        {{-- Badges --}}
        <section class="space-y-4" aria-labelledby="s-badge">
            <h2 id="s-badge" class="font-display text-2xl font-semibold">Badge and status badge</h2>
            <x-ui.card><div class="space-y-6">
                <div>
                    <p class="mb-3 text-sm font-semibold text-app-text-2">Tones</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (['amber', 'blue', 'violet', 'green', 'gray', 'red'] as $tone)
                            <x-ui.badge :tone="$tone">{{ ucfirst($tone) }}</x-ui.badge>
                        @endforeach
                        <x-ui.badge tone="green" :dot="false">No dot</x-ui.badge>
                    </div>
                </div>
                <div>
                    <p class="mb-3 text-sm font-semibold text-app-text-2">Printing order, booking and payment statuses</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (['submitted', 'under_review', 'accepted', 'rejected', 'processing', 'ready_for_pickup', 'completed', 'cancelled', 'awaiting_customer_info', 'awaiting_schedule_confirmation', 'confirmed', 'reschedule_proposed', 'photoshoot_completed', 'unpaid', 'partially_paid', 'paid'] as $status)
                            <x-ui.status-badge :status="$status" />
                        @endforeach
                    </div>
                </div>
            </div></x-ui.card>
        </section>

        {{-- Form fields --}}
        <section class="space-y-4" aria-labelledby="s-form">
            <h2 id="s-form" class="font-display text-2xl font-semibold">Input, select, textarea, checkbox</h2>
            <x-ui.card>
                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <x-ui.input label="Default" name="kit_default" placeholder="Placeholder text" hint="A short hint under the field." />
                    <x-ui.input label="With icon" name="kit_icon" icon="mail" type="email" placeholder="name@example.com" />
                    <x-ui.input label="Password" name="kit_password" type="password" value="sample-password" icon="lock" hint="Use the eye button to show or hide it." />
                    <x-ui.input label="Required" name="kit_required" :required="true" placeholder="Required field" />
                    <x-ui.input label="Error state" name="kit_error" value="Wrong value" error="This is an example error message." />
                    <x-ui.input label="Disabled" name="kit_disabled" value="Cannot edit" :disabled="true" />

                    <x-ui.select label="Select" name="kit_select" hint="Native select with a custom arrow.">
                        <option value="">Choose an option</option>
                        <option value="a">Option A</option>
                        <option value="b">Option B</option>
                        <option value="c">Option C</option>
                    </x-ui.select>
                    <x-ui.select label="Select with error" name="kit_select_error" error="Please choose an option.">
                        <option value="">Choose an option</option>
                        <option value="a">Option A</option>
                    </x-ui.select>
                    <x-ui.select label="Select disabled" name="kit_select_disabled" :disabled="true">
                        <option>Option A</option>
                    </x-ui.select>

                    <x-ui.textarea label="Textarea" name="kit_textarea" placeholder="Write a few lines here" hint="You can resize it vertically." />
                    <x-ui.textarea label="Textarea with error" name="kit_textarea_error" error="This is an example error message." />
                    <x-ui.textarea label="Textarea disabled" name="kit_textarea_disabled" :disabled="true">Cannot edit</x-ui.textarea>

                    <div>
                        <x-ui.checkbox label="Checkbox, unchecked" name="kit_check_1" />
                        <x-ui.checkbox label="Checkbox, checked" name="kit_check_2" :checked="true" hint="With a hint underneath." />
                        <x-ui.checkbox label="Checkbox, disabled" name="kit_check_3" :disabled="true" />
                        <x-ui.checkbox label="Checkbox with error" name="kit_check_4" error="You must tick this box." />
                    </div>
                </div>
            </x-ui.card>
        </section>

        {{-- Cards --}}
        <section class="space-y-4" aria-labelledby="s-card">
            <h2 id="s-card" class="font-display text-2xl font-semibold">Card</h2>
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.card>
                    <h3 class="font-display text-lg font-semibold">Plain card</h3>
                    <p class="mt-1 text-sm text-app-text-2">No hover effect.</p>
                </x-ui.card>
                <x-ui.card :hover="true">
                    <h3 class="font-display text-lg font-semibold">Hover lift</h3>
                    <p class="mt-1 text-sm text-app-text-2">Moves up 3px when hovered.</p>
                </x-ui.card>
                <x-ui.card :hover="true" class="sm:col-span-2 lg:col-span-1">
                    <x-slot:image>
                        <div class="aspect-[4/3] bg-gradient-to-br from-neutral-300 to-neutral-500 dark:from-neutral-700 dark:to-neutral-900"></div>
                    </x-slot:image>
                    <h3 class="font-display text-lg font-semibold">Card with image</h3>
                    <p class="mt-1 text-sm text-app-text-2">The image zooms to 1.03 on hover.</p>
                </x-ui.card>
            </div>
        </section>

        {{-- Notices --}}
        <section class="space-y-4" aria-labelledby="s-notice">
            <h2 id="s-notice" class="font-display text-2xl font-semibold">Notice</h2>
            <div class="grid gap-4 lg:grid-cols-2">
                <x-ui.notice>This is an informational notice with some generic text.</x-ui.notice>
                <x-ui.notice variant="payment" />
            </div>
        </section>

        {{-- Modal --}}
        <section class="space-y-4" aria-labelledby="s-modal">
            <h2 id="s-modal" class="font-display text-2xl font-semibold">Modal</h2>
            <x-ui.card><div class="space-y-3">
                <p class="text-sm text-app-text-2">Closes with Esc, a click outside, or the close button. Focus returns to this button.</p>
                <x-ui.button id="kit-modal-trigger" x-on:click="$dispatch('open-modal', 'kit-demo')">Open modal</x-ui.button>
                <x-ui.button variant="outline" x-on:click="$dispatch('open-modal', 'kit-tall')">Open tall modal</x-ui.button>
            </div></x-ui.card>

            <x-ui.modal name="kit-demo" title="Example dialog">
                <p class="text-app-text-2">This is generic dialog text. Press Tab to move between the controls.</p>
                <x-ui.input class="mt-4" label="Example field" name="kit_modal_field" placeholder="Type here" />
                <x-slot:footer>
                    <x-ui.button variant="outline" x-on:click="$dispatch('close-modal')">Cancel</x-ui.button>
                    <x-ui.button x-on:click="$dispatch('close-modal')">Confirm</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>

            <x-ui.modal name="kit-tall" title="Tall dialog">
                @foreach (range(1, 16) as $line)
                    <p class="mb-3 text-app-text-2">Line {{ $line }}: generic text to make this dialog scroll inside itself on short screens.</p>
                @endforeach
                <x-slot:footer>
                    <x-ui.button x-on:click="$dispatch('close-modal')">Close</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        </section>

        {{-- Toast --}}
        <section class="space-y-4" aria-labelledby="s-toast">
            <h2 id="s-toast" class="font-display text-2xl font-semibold">Toast</h2>
            <x-ui.card><div class="space-y-3">
                <p class="text-sm text-app-text-2">Each toast dismisses itself after 5 seconds, or with its close button.</p>
                <div class="flex flex-wrap gap-3">
                    <x-ui.button x-on:click="$dispatch('toast', { message: 'Your changes were saved.', variant: 'success' })">Success toast</x-ui.button>
                    <x-ui.button variant="danger" x-on:click="$dispatch('toast', { message: 'Something went wrong. Please try again.', variant: 'error' })">Error toast</x-ui.button>
                    <x-ui.button variant="outline" x-on:click="$dispatch('toast', { message: 'This is some general information.', variant: 'info' })">Info toast</x-ui.button>
                </div>
            </div></x-ui.card>
        </section>

        {{-- Empty state --}}
        <section class="space-y-4" aria-labelledby="s-empty">
            <h2 id="s-empty" class="font-display text-2xl font-semibold">Empty state</h2>
            <div class="grid gap-6 md:grid-cols-2">
                <x-ui.card>
                    <x-ui.empty-state icon="receipt_long" title="Nothing here yet" description="When there is something to show, it will appear in this space." />
                </x-ui.card>
                <x-ui.card>
                    <x-ui.empty-state icon="photo_library" title="No items found" description="Try a different filter, or add a new item.">
                        <x-ui.button>Primary action</x-ui.button>
                    </x-ui.empty-state>
                </x-ui.card>
            </div>
        </section>

        {{-- Stepper --}}
        <section class="space-y-4" aria-labelledby="s-stepper">
            <h2 id="s-stepper" class="font-display text-2xl font-semibold">Stepper</h2>
            <x-ui.card x-data="{ step: 2 }"><div class="space-y-6">
                <x-ui.stepper :steps="['Details', 'Upload', 'Review', 'Done']" x-model="step" />
                <div class="flex flex-wrap gap-3">
                    <x-ui.button variant="outline" size="sm" x-on:click="step = Math.max(1, step - 1)">Back</x-ui.button>
                    <x-ui.button size="sm" x-on:click="step = Math.min(4, step + 1)">Next</x-ui.button>
                </div>
            </div></x-ui.card>
            <x-ui.card>
                <x-ui.stepper :steps="['One', 'Two', 'Three']" :current="1" />
            </x-ui.card>
        </section>

        {{-- Timeline --}}
        <section class="space-y-4" aria-labelledby="s-timeline">
            <h2 id="s-timeline" class="font-display text-2xl font-semibold">Timeline</h2>
            <div class="grid gap-6 md:grid-cols-2">
                <x-ui.card>
                    <x-ui.timeline :events="[
                        ['title' => 'Request submitted', 'description' => 'Example description text.', 'at' => 'Sample date'],
                        ['title' => 'Under review', 'at' => 'Sample date', 'by' => 'Sample name'],
                        ['title' => 'Accepted', 'description' => 'A short note can go here.', 'at' => 'Sample date', 'by' => 'Sample name'],
                    ]" />
                </x-ui.card>
                <x-ui.card>
                    <x-ui.timeline :events="[['title' => 'Only one event given']]" />
                </x-ui.card>
            </div>
        </section>

        {{-- Aperture spinner and page loader --}}
        <section class="space-y-4" aria-labelledby="s-loader">
            <h2 id="s-loader" class="font-display text-2xl font-semibold">Aperture spinner and page loader</h2>
            <x-ui.card><div class="space-y-4">
                <div class="flex flex-wrap items-center gap-6 text-brand">
                    <x-ui.aperture-spinner :size="20" label="Loading" />
                    <x-ui.aperture-spinner :size="32" label="Loading" />
                    <x-ui.aperture-spinner :size="48" label="Loading" />
                </div>
                <x-ui.button variant="outline" x-on:click="$dispatch('replay-loader')">Replay page loader</x-ui.button>
            </div></x-ui.card>
        </section>
    </main>
@endsection
