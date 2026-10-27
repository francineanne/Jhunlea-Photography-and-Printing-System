<?php

namespace App\Http\Controllers\Owner;

use App\Enums\OptionGroup;
use App\Enums\PricingType;
use App\Enums\PrintFileType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ServiceRequest;
use App\Models\PrintingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServiceController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        // An array such as ?search[]=x is ignored instead of crashing
        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';
        $category = is_string($request->query('category')) ? $request->query('category') : '';
        $status = in_array($request->query('status'), ['published', 'draft'], true) ? $request->query('status') : null;

        $services = PrintingService::query()
            ->withCount('options')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->when($status, fn ($query) => $query->where('is_published', $status === 'published'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('owner.services.index', [
            'services' => $services,
            'categories' => PrintingService::query()->distinct()->orderBy('category')->pluck('category'),
            'search' => $search,
            'category' => $category,
            'status' => $status,
            'hasFilters' => $search !== '' || $category !== '' || $status !== null,
        ]);
    }

    public function create(): View
    {
        return view('owner.services.create', $this->formData(new PrintingService([
            'pricing_type' => PricingType::PerPage,
            'allowed_file_types' => [PrintFileType::Pdf->value],
            'max_file_size_mb' => 2,
            'sort_order' => 0,
            'is_published' => false,
        ])));
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        $data = $this->attributes($request);

        try {
            $service = PrintingService::create($data);
        } catch (UniqueConstraintViolationException) {
            // Another request took the same address a moment ago: pick the next free one
            $service = PrintingService::create(['slug' => PrintingService::uniqueSlug($data['name'])] + $data);
        }

        return redirect()
            ->route('owner.services.edit', $service)
            ->with('status', "\"{$service->name}\" was created. You can add its options below.");
    }

    public function edit(PrintingService $service): View
    {
        $service->load(['options' => fn ($query) => $query->orderBy('label')]);

        return view('owner.services.edit', $this->formData($service) + [
            'optionsByGroup' => $service->options->groupBy(fn ($option) => $option->group->value),
            'groups' => OptionGroup::cases(),
        ]);
    }

    public function update(ServiceRequest $request, PrintingService $service): RedirectResponse
    {
        $service->update($this->attributes($request, $service));

        return redirect()
            ->route('owner.services.edit', $service)
            ->with('status', "\"{$service->name}\" was saved.");
    }

    public function destroy(PrintingService $service): RedirectResponse
    {
        $service->delete();

        return redirect()
            ->route('owner.services.index')
            ->with('status', "\"{$service->name}\" was deleted.");
    }

    public function publish(PrintingService $service): RedirectResponse
    {
        $service->update(['is_published' => ! $service->is_published]);

        return back()->with('status', $service->is_published
            ? "\"{$service->name}\" is now published."
            : "\"{$service->name}\" is now unpublished.");
    }

    /** @return array<string, mixed> */
    private function attributes(ServiceRequest $request, ?PrintingService $service = null): array
    {
        $data = $request->validated();

        // A blank address is generated from the name; on edit a blank keeps the current one, so links stay stable
        $data['slug'] = ($data['slug'] ?? null)
            ?: ($service?->slug ?? PrintingService::uniqueSlug($data['name']));
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['allowed_file_types'] = array_values($data['allowed_file_types']);
        $data['base_price'] = ($data['base_price'] ?? '') === '' ? null : $data['base_price'];

        return $data;
    }

    /** @return array<string, mixed> */
    private function formData(PrintingService $service): array
    {
        return [
            'service' => $service,
            'pricingTypes' => PricingType::cases(),
            'fileTypes' => PrintFileType::cases(),
            'categories' => PrintingService::query()->distinct()->orderBy('category')->pluck('category'),
            'maxFileSize' => ServiceRequest::MAX_FILE_SIZE_MB,
            'serverUploadLimit' => ini_get('upload_max_filesize'),
        ];
    }
}
