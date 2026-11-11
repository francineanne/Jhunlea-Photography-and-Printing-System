<?php

namespace App\Http\Controllers\Owner;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ServiceOptionRequest;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/** The routes use scoped bindings, so {option} must belong to {service} or the request is a 404. */
class ServiceOptionController extends Controller
{
    public function store(ServiceOptionRequest $request, PrintingService $service): RedirectResponse
    {
        // The change and its audit row in one transaction (8E): a failed audit write undoes the change
        $option = DB::transaction(function () use ($request, $service) {
            $option = $service->options()->create($this->attributes($request));
            app(AuditLogger::class)->catalog(AuditAction::CatalogCreated, $option, 'option of '.$service->name);

            return $option;
        });

        return $this->back($service, "Option \"{$option->label}\" was added.");
    }

    public function update(ServiceOptionRequest $request, PrintingService $service, PrintingServiceOption $option): RedirectResponse
    {
        DB::transaction(function () use ($request, $service, $option) {
            $option->update($this->attributes($request));
            if ($option->wasChanged()) {
                app(AuditLogger::class)->catalog(AuditAction::CatalogUpdated, $option, 'option of '.$service->name);
            }
        });

        return $this->back($service, "Option \"{$option->label}\" was saved.");
    }

    public function destroy(PrintingService $service, PrintingServiceOption $option): RedirectResponse
    {
        DB::transaction(function () use ($service, $option) {
            $option->delete();
            app(AuditLogger::class)->catalog(AuditAction::CatalogDeleted, $option, 'option of '.$service->name);
        });

        return $this->back($service, "Option \"{$option->label}\" was removed.");
    }

    /** @return array<string, mixed> */
    private function attributes(ServiceOptionRequest $request): array
    {
        $data = $request->validated();
        $data['price_modifier'] = ($data['price_modifier'] ?? '') === '' ? '0.00' : $data['price_modifier'];

        return $data;
    }

    private function back(PrintingService $service, string $message): RedirectResponse
    {
        return redirect()->to(route('owner.services.edit', $service).'#options')->with('status', $message);
    }
}
