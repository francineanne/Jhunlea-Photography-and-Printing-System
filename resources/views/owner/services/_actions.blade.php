{{-- Row actions for one service. Needs $service and sits inside the page's x-data (delete modal target). --}}
<div class="flex flex-wrap items-center gap-2">
    <x-ui.button :href="route('owner.services.edit', $service)" variant="outline" size="sm">
        <x-ui.icon name="edit" :size="18" /> Edit<span class="sr-only"> {{ $service->name }}</span>
    </x-ui.button>

    <form method="POST" action="{{ route('owner.services.publish', $service) }}">
        @csrf
        <x-ui.button type="submit" variant="ghost" size="sm">
            <x-ui.icon :name="$service->is_published ? 'visibility_off' : 'visibility'" :size="18" />
            {{ $service->is_published ? 'Unpublish' : 'Publish' }}<span class="sr-only"> {{ $service->name }}</span>
        </x-ui.button>
    </form>

    <x-ui.button type="button" variant="ghost" size="sm" class="text-status-red"
        x-on:click="target = { name: {{ \Illuminate\Support\Js::from($service->name) }}, action: {{ \Illuminate\Support\Js::from(route('owner.services.destroy', $service)) }} }; $dispatch('open-modal', 'delete-service')">
        <x-ui.icon name="delete" :size="18" /> Delete<span class="sr-only"> {{ $service->name }}</span>
    </x-ui.button>
</div>
