<?php

namespace App\Http\Controllers\Public;

use App\Enums\OptionGroup;
use App\Http\Controllers\Controller;
use App\Models\PrintingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServiceController extends Controller
{
    private const PER_PAGE = 12;

    public function index(Request $request): View|RedirectResponse
    {
        // Anything that is not plain text (for example ?q[]=x) is ignored instead of crashing
        $search = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 60) : '';
        $category = is_string($request->query('category')) ? mb_substr(trim($request->query('category')), 0, 60) : '';

        $services = PrintingService::query()
            ->published()
            ->with(['options' => fn ($query) => $query->active()->orderBy('label')])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        if ($services->isEmpty() && $services->total() > 0) {
            return redirect($services->url($services->lastPage()));
        }

        return view('public.services.index', [
            'services' => $services,
            // Per category for the chips: the whole published catalog, not the current search
            'categories' => PrintingService::query()->published()->selectRaw('category, COUNT(*) as total')->groupBy('category')->orderBy('category')->pluck('total', 'category'),
            'search' => $search,
            'category' => $category,
            'optionGroups' => OptionGroup::cases(),
        ]);
    }
}
