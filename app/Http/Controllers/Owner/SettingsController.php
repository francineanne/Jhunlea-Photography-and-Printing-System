<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Settings\BusinessInfoRequest;
use App\Http\Requests\Owner\Settings\ResetUploadLimitsRequest;
use App\Http\Requests\Owner\Settings\UploadLimitsRequest;
use App\Models\AvailabilityRule;
use App\Models\BookingSetting;
use App\Models\Faq;
use App\Services\ShopSettingsService;
use App\Support\ShopSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The owner's Settings page (Phase 6B). Business info and upload limits are edited here. The opening hours and booking
 * rules are only SHOWN: the Schedule editor is the one place that changes them. The payment rules are fixed facts.
 */
class SettingsController extends Controller
{
    public function index(ShopSettings $settings): View
    {
        return view('owner.settings.index', [
            'business' => $settings->business(),
            'limits' => collect(ShopSettings::LIMITS)->map(fn ($rule, string $field) => [
                'label' => UploadLimitsRequest::LABELS[$field],
                'value' => match ($field) {
                    'printing_max_file_mb' => $settings->printingMaxFileMb(),
                    'printing_max_total_mb' => $settings->printingMaxTotalMb(),
                    'printing_max_files' => $settings->printingMaxFiles(),
                    'booking_max_image_mb' => $settings->bookingMaxImageMb(),
                    'booking_max_images' => $settings->bookingMaxImages(),
                    'booking_max_total_mb' => $settings->bookingMaxTotalMb(),
                },
                'default' => $settings->defaultFor($field),
                'stored' => $settings->storedFor($field),
                'max' => $settings->serverMax($field),
                'capped' => $settings->capped($field),
                'unit' => $rule['cap'] === 'files' ? 'files' : 'MB',
            ])->all(),
            'usesDefaults' => collect(ShopSettings::LIMITS)->keys()->every(fn (string $field) => $settings->storedFor($field) === null),
            'server' => $settings->server()->raw(),
            'printingTypes' => array_keys(config('printing.types')),
            'bookingTypes' => array_keys(config('booking.types')),
            'schedule' => BookingSetting::current(),
            'week' => AvailabilityRule::week(),
            'cancelCutoffHours' => (int) config('booking.customer_cancel_cutoff_hours'),
            'maxOpenRequests' => (int) config('booking.max_open_requests_per_customer'),
            'faqCount' => Faq::query()->count(),
            'faqPublished' => Faq::query()->published()->count(),
        ]);
    }

    public function business(BusinessInfoRequest $request, ShopSettingsService $service): RedirectResponse
    {
        $service->saveBusiness($request->info());

        return redirect()->to(route('owner.settings.index').'#business')->with('status', 'Business info saved. The public site shows it now.');
    }

    public function uploads(UploadLimitsRequest $request, ShopSettingsService $service): RedirectResponse
    {
        $service->saveLimits($request->limits());

        return redirect()->to(route('owner.settings.index').'#uploads')->with('status', 'Upload limits saved. New uploads use them now; files already sent are not changed.');
    }

    public function resetUploads(ResetUploadLimitsRequest $request, ShopSettingsService $service): RedirectResponse
    {
        $service->resetLimits();

        return redirect()->to(route('owner.settings.index').'#uploads')->with('status', 'Upload limits are back to the starting defaults.');
    }
}
