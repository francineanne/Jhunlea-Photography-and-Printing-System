<?php

namespace App\Services\Audit;

use App\Models\BlockedDate;
use App\Models\Faq;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The short type name and label the audit trail keeps for a record (Phase 8E), and the owner page that shows it. Its own
 * map, so no model has to join the strict morph map. A label is a reference number or a short name, never contact data.
 */
final class AuditSubject
{
    public const TYPES = [
        'printing_order' => PrintingOrder::class,
        'photography_booking' => PhotographyBooking::class,
        'payment' => Payment::class,
        'user' => User::class,
        'printing_service' => PrintingService::class,
        'printing_service_option' => PrintingServiceOption::class,
        'photography_package' => PhotographyPackage::class,
        'theme' => Theme::class,
        'faq' => Faq::class,
        'blocked_date' => BlockedDate::class,
    ];

    public const NAMES = [
        'printing_order' => 'Printing order',
        'photography_booking' => 'Booking',
        'payment' => 'Payment',
        'user' => 'Customer',
        'printing_service' => 'Printing service',
        'printing_service_option' => 'Service option',
        'photography_package' => 'Package',
        'theme' => 'Theme',
        'faq' => 'FAQ',
        'blocked_date' => 'Blocked date',
    ];

    public static function type(Model $model): ?string
    {
        return array_search($model::class, self::TYPES, true) ?: null;
    }

    public static function label(Model $model): string
    {
        $label = match (true) {
            $model instanceof PrintingOrder, $model instanceof PhotographyBooking, $model instanceof Payment => $model->reference_number,
            $model instanceof PrintingServiceOption => $model->label,
            $model instanceof Faq => $model->question,
            $model instanceof BlockedDate => $model->date instanceof \DateTimeInterface ? $model->date->format('Y-m-d') : (string) $model->date,
            default => $model->getAttribute('name'),
        };

        return mb_substr((string) $label, 0, 120);
    }

    /** The owner page of a subject, or null when it has none. */
    public static function route(string $type, int $id): ?string
    {
        return match ($type) {
            'printing_order' => route('owner.orders.show', $id),
            'photography_booking' => route('owner.bookings.show', $id),
            'payment' => route('owner.payments.receipt', $id),
            'user' => route('owner.customers.show', $id),
            'printing_service' => route('owner.services.edit', $id),
            'photography_package' => route('owner.packages.edit', $id),
            'theme' => route('owner.themes.edit', $id),
            'faq' => route('owner.faqs.edit', $id),
            default => null,
        };
    }
}
