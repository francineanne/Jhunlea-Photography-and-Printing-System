<?php

namespace App\Enums;

/**
 * Every action the audit trail records (Phase 8E), with its label, its filter group and the ONLY meta keys it may keep
 * (AuditLogger drops anything else). Never logged: message bodies, GCash references, passwords, tokens, keys, session
 * ids, phone numbers, email addresses, file names or contents, and the AI's prompts or answers.
 */
enum AuditAction: string
{
    case PaymentRecorded = 'payment.recorded';
    case PaymentVoided = 'payment.voided';
    case ReceiptOpened = 'payment.receipt_opened';

    case OrderWalkInCreated = 'order.walk_in_created';
    case OrderStatusChanged = 'order.status_changed';
    case OrderPriceChanged = 'order.price_changed';
    case OrderCancelled = 'order.cancelled';
    case OrderRejected = 'order.rejected';
    case OrderCompleted = 'order.completed';

    case BookingWalkInCreated = 'booking.walk_in_created';
    case BookingStatusChanged = 'booking.status_changed';
    case BookingPriceChanged = 'booking.price_changed';
    case BookingCancelled = 'booking.cancelled';
    case BookingRejected = 'booking.rejected';
    case BookingCompleted = 'booking.completed';

    case WalkInCustomerCreated = 'customer.walk_in_created';

    case HoursChanged = 'schedule.hours_changed';
    case ScheduleRulesChanged = 'schedule.rules_changed';
    case DateBlocked = 'schedule.date_blocked';
    case DateUnblocked = 'schedule.date_unblocked';

    case CatalogCreated = 'catalog.created';
    case CatalogUpdated = 'catalog.updated';
    case CatalogPublished = 'catalog.published';
    case CatalogUnpublished = 'catalog.unpublished';
    case CatalogDeleted = 'catalog.deleted';

    case FaqCreated = 'faq.created';
    case FaqUpdated = 'faq.updated';
    case FaqPublished = 'faq.published';
    case FaqUnpublished = 'faq.unpublished';
    case FaqDeleted = 'faq.deleted';

    case ReportCsvExported = 'report.csv_exported';
    case EndOfDayOpened = 'report.end_of_day_opened';
    case AuditExported = 'audit.exported';
    case AuditPruned = 'audit.pruned';

    case LoggedIn = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case PasswordChanged = 'account.password_changed';
    case EmailChanged = 'account.email_changed';
    case SessionsSignedOut = 'account.sessions_signed_out';

    case SettingsChanged = 'settings.changed';

    public const GROUPS = [
        'payments' => 'Payments',
        'orders' => 'Printing orders',
        'bookings' => 'Bookings',
        'customers' => 'Customers',
        'schedule' => 'Schedule',
        'catalog' => 'Catalog',
        'faq' => 'FAQ',
        'reports' => 'Reports and exports',
        'account' => 'Sign-in and account',
        'settings' => 'Settings',
    ];

    public function group(): string
    {
        return match (explode('.', $this->value)[0]) {
            'payment' => 'payments',
            'order' => 'orders',
            'booking' => 'bookings',
            'customer' => 'customers',
            'schedule' => 'schedule',
            'catalog' => 'catalog',
            'faq' => 'faq',
            'report', 'audit' => 'reports',
            'auth', 'account' => 'account',
            default => 'settings',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PaymentRecorded => 'Payment recorded',
            self::PaymentVoided => 'Payment voided',
            self::ReceiptOpened => 'Receipt opened',
            self::OrderWalkInCreated => 'Walk-in order made',
            self::OrderStatusChanged => 'Order status changed',
            self::OrderPriceChanged => 'Order price changed',
            self::OrderCancelled => 'Order cancelled',
            self::OrderRejected => 'Order rejected',
            self::OrderCompleted => 'Order released',
            self::BookingWalkInCreated => 'Walk-in booking made',
            self::BookingStatusChanged => 'Booking status changed',
            self::BookingPriceChanged => 'Booking price changed',
            self::BookingCancelled => 'Booking cancelled',
            self::BookingRejected => 'Booking rejected',
            self::BookingCompleted => 'Booking completed',
            self::WalkInCustomerCreated => 'Walk-in customer added',
            self::HoursChanged => 'Opening hours changed',
            self::ScheduleRulesChanged => 'Booking rules changed',
            self::DateBlocked => 'Date blocked',
            self::DateUnblocked => 'Date unblocked',
            self::CatalogCreated => 'Catalog item added',
            self::CatalogUpdated => 'Catalog item edited',
            self::CatalogPublished => 'Catalog item published',
            self::CatalogUnpublished => 'Catalog item hidden',
            self::CatalogDeleted => 'Catalog item deleted',
            self::FaqCreated => 'FAQ added',
            self::FaqUpdated => 'FAQ edited',
            self::FaqPublished => 'FAQ published',
            self::FaqUnpublished => 'FAQ hidden',
            self::FaqDeleted => 'FAQ deleted',
            self::ReportCsvExported => 'Income CSV exported',
            self::EndOfDayOpened => 'End-of-day summary opened',
            self::AuditExported => 'Activity log exported',
            self::AuditPruned => 'Old activity pruned',
            self::LoggedIn => 'Signed in',
            self::LoginFailed => 'Failed sign-in',
            self::PasswordChanged => 'Password changed',
            self::EmailChanged => 'Email changed',
            self::SessionsSignedOut => 'Other devices signed out',
            self::SettingsChanged => 'Settings changed',
        };
    }

    /** The meta keys this action may keep; anything else is dropped. @return list<string> */
    public function metaKeys(): array
    {
        return match ($this->group()) {
            'payments' => ['amount_cents', 'method', 'for', 'reason', 'date'],
            'orders', 'bookings' => ['from', 'to', 'price_cents', 'old_cents', 'new_cents', 'reason', 'customer'],
            'schedule' => ['hours', 'changes', 'dates', 'date', 'reason'],
            'catalog' => ['kind', 'name'],
            'faq' => ['question'],
            'reports' => ['view', 'from', 'to', 'date', 'rows', 'group', 'search', 'pruned_up_to_id', 'anchor_hash'],
            'account' => ['count'],
            'settings' => ['keys', 'changes'],
            default => [],
        };
    }

    /** @return list<self> */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(self::cases(), fn (self $action) => $action->group() === $group));
    }
}
