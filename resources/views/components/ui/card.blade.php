@props(['hover' => false, 'padded' => true, 'design' => false])

<div {{ $attributes->class([
    'group rounded-card border border-app-border bg-app-card shadow-[0_2px_8px_-2px_rgba(23,23,23,0.04),0_1px_4px_-1px_rgba(23,23,23,0.02)] dark:shadow-none',
    'overflow-hidden' => isset($image),
    'transition-[transform,translate,box-shadow] duration-[280ms] ease-brand hover:-translate-y-[3px] hover:shadow-[0_12px_24px_-6px_rgba(23,23,23,0.08),0_4px_8px_-2px_rgba(23,23,23,0.03)] dark:hover:shadow-[0_10px_28px_rgba(0,0,0,0.5)]' => $hover && ! $design,
    // The public Stitch screens: no lift, the shadow deepens over 300ms and the photo zoom is on the img itself, so controls laid over it do not move
    'transition-shadow duration-300 hover:shadow-[0_20px_25px_-5px_rgba(23,23,23,0.1),0_8px_10px_-6px_rgba(23,23,23,0.06)] dark:hover:shadow-[0_10px_28px_rgba(0,0,0,0.5)]' => $hover && $design,
]) }}>
    @isset($image)
        <div class="overflow-hidden">
            <div @class([
                'transition-transform',
                'duration-[280ms] ease-brand' => ! $design,
                'duration-500 ease-out' => $design,
                'group-hover:scale-[1.03]' => $hover && ! $design,
            ])>
                {{ $image }}
            </div>
        </div>
    @endisset

    <div @class(['p-4 sm:p-6' => $padded])>{{ $slot }}</div>
</div>
