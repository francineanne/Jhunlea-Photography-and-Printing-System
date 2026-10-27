{{-- The photo gallery of one package (edit page). Needs $package with its images loaded; sits inside the page's x-data (delete modal target). --}}
@php
    use App\Models\PackageImage;

    $total = $package->images->count();
    $room = PackageImage::MAX_PER_PACKAGE - $total;
    $uploadError = $errors->first('images') ?: $errors->first('images.*');
@endphp

<section id="gallery" aria-labelledby="gallery-heading" x-data="{ chosen: 0 }" class="scroll-mt-24">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <h2 id="gallery-heading" class="font-display text-2xl font-semibold">Photos</h2>
        <p class="font-mono text-xs text-app-text-2">{{ $total }} of {{ PackageImage::MAX_PER_PACKAGE }} photos</p>
    </div>
    <p class="mt-1 text-sm text-app-text-2">The first photo is the cover. Customers see these photos on the package.</p>

    @if ($room > 0)
        <form method="POST" action="{{ route('owner.packages.images.store', $package) }}" enctype="multipart/form-data" class="mt-5">
            @csrf
            <label class="group flex min-h-40 cursor-pointer flex-col items-center justify-center gap-2 rounded-card border-2 border-dashed border-neutral-300 bg-app-card px-4 py-8 text-center transition-colors hover:border-brand hover:bg-brand/5 focus-within:border-brand focus-within:ring-[3px] focus-within:ring-brand/20 dark:border-neutral-700"
                for="package-images-input">
                <x-ui.icon name="cloud_upload" :size="40" class="text-app-text-2 transition-transform duration-300 group-hover:-translate-y-0.5 group-hover:text-brand" />
                <span class="font-semibold">Choose photos to upload</span>
                <span class="text-sm text-app-text-2">JPG, PNG or WebP, up to {{ PackageImage::MAX_SIZE_KB / 1024 }} MB each, {{ PackageImage::MAX_PER_REQUEST }} at a time, {{ ini_get('post_max_size') }}B in total. You can add {{ $room }} more.</span>
                <span class="text-sm font-semibold text-brand" x-show="chosen > 0" x-cloak x-text="chosen + (chosen === 1 ? ' photo chosen' : ' photos chosen')" aria-live="polite"></span>
                <input id="package-images-input" type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp" class="sr-only"
                    @if ($uploadError) aria-invalid="true" aria-describedby="package-images-error" @endif
                    x-on:change="chosen = $event.target.files.length">
            </label>

            @if ($uploadError)
                <p id="package-images-error" class="mt-2 flex items-start gap-1 text-sm text-status-red"><x-ui.icon name="error" :size="16" class="mt-0.5" /> <span>{{ $uploadError }}</span></p>
            @endif

            <x-ui.button type="submit" class="mt-4"><x-ui.icon name="upload" :size="18" /> Upload photos</x-ui.button>
        </form>
    @else
        <x-ui.notice class="mt-5">This package has the most photos it can hold ({{ PackageImage::MAX_PER_PACKAGE }}). Delete one to add another.</x-ui.notice>
    @endif

    @if ($package->images->isEmpty())
        <x-ui.card class="mt-6">
            <x-ui.empty-state icon="photo_library" title="No photos yet" description="Upload a few photos so customers can see this package. The first one becomes the cover." />
        </x-ui.card>
    @else
        <ul class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($package->images as $image)
                @php
                    $bag = $errors->getBag('image_'.$image->id);
                    $alt = $bag->any() ? old('alt_text') : $image->alt_text;
                @endphp
                <li class="flex">
                    <x-ui.card hover :padded="false" class="flex w-full flex-col">
                        <x-slot:image>
                            <div class="relative aspect-[4/3]">
                                <img src="{{ $image->url() }}" alt="{{ $image->alt_text ?: $package->name }}" loading="lazy" class="size-full object-cover">
                                @if ($image->is_cover)
                                    <span class="absolute left-3 top-3 inline-flex items-center gap-1 rounded-full bg-brand px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-white shadow-sm"><x-ui.icon name="star" :size="14" /> Cover</span>
                                @endif
                            </div>
                        </x-slot:image>

                        <div class="flex flex-1 flex-col gap-3 p-4">
                            <form method="POST" action="{{ route('owner.packages.images.update', [$package, $image]) }}" class="space-y-2">
                                @csrf
                                @method('PUT')
                                <x-ui.input label="Describe this photo" name="alt_text" :id="'alt-'.$image->id" :value="$alt" :error="$bag->first('alt_text')" maxlength="150"
                                    hint="Read aloud by screen readers. Optional." />
                                <x-ui.button type="submit" size="sm" variant="outline">Save description</x-ui.button>
                            </form>

                            <div class="mt-auto flex flex-wrap items-center gap-2 border-t border-app-border pt-3">
                                @unless ($image->is_cover)
                                    <form method="POST" action="{{ route('owner.packages.images.cover', [$package, $image]) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="ghost"><x-ui.icon name="star" :size="18" /> Set as cover</x-ui.button>
                                    </form>
                                @endunless
                                <x-ui.button type="button" size="sm" variant="ghost" class="ml-auto text-status-red"
                                    x-on:click="target = { name: {{ \Illuminate\Support\Js::from($image->alt_text ?: 'this photo') }}, action: {{ \Illuminate\Support\Js::from(route('owner.packages.images.destroy', [$package, $image])) }} }; $dispatch('open-modal', 'delete-image')">
                                    <x-ui.icon name="delete" :size="18" /> Delete<span class="sr-only"> photo {{ $loop->iteration }}</span>
                                </x-ui.button>
                            </div>
                        </div>
                    </x-ui.card>
                </li>
            @endforeach
        </ul>
    @endif
</section>
