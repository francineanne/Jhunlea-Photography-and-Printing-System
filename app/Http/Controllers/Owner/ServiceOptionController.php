<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ServiceOptionRequest;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use Illuminate\Http\RedirectResponse;

/** The routes use scoped bindings, so {option} must belong to {service} or the request is a 404. */
class ServiceOptionController extends Controller
{
    public function store(ServiceOptionRequest $request, PrintingService $service): RedirectResponse
    {
        $option = $service->options()->create($this->attributes($request));

        return $this->back($service, "Option \"{$option->label}\" was added.");
    }

    public function update(ServiceOptionRequest $request, PrintingService $service, PrintingServiceOption $option): RedirectResponse
    {
        $option->update($this->attributes($request));

        return $this->back($service, "Option \"{$option->label}\" was saved.");
    }

    public function destroy(PrintingService $service, PrintingServiceOption $option): RedirectResponse
    {
        $option->delete();

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
