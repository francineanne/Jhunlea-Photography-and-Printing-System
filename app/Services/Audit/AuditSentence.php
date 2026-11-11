<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PrintingOrderStatus;
use App\Support\Money;

/**
 * The one short plain sentence of an audit row (Phase 8E), written by code from the action, the subject's label and the
 * whitelisted meta: "Voided PAY-000012 (₱500.00) for PR-000123. Reason: wrong order." The Activity log puts the actor
 * in front of it.
 */
final class AuditSentence
{
    /** @param array<string, mixed> $meta */
    public static function make(AuditAction $action, ?string $label, array $meta): string
    {
        $subject = $label ?? '';
        $money = fn (string $key) => isset($meta[$key]) ? Money::cents((int) $meta[$key]) : null;
        $method = isset($meta['method']) ? (PaymentMethod::tryFrom((string) $meta['method'])?->label() ?? (string) $meta['method']) : null;
        $reason = isset($meta['reason']) && $meta['reason'] !== '' ? ' Reason: '.rtrim((string) $meta['reason'], '.').'.' : '';
        $status = fn (string $key) => isset($meta[$key]) ? (PrintingOrderStatus::tryFrom((string) $meta[$key])?->label() ?? BookingStatus::tryFrom((string) $meta[$key])?->label() ?? (string) $meta[$key]) : '?';

        $text = match ($action) {
            AuditAction::PaymentRecorded => "Recorded {$subject} (".$money('amount_cents').', '.$method.') for '.($meta['for'] ?? '?').'.',
            AuditAction::PaymentVoided => "Voided {$subject} (".$money('amount_cents').') for '.($meta['for'] ?? '?').'.'.$reason,
            AuditAction::ReceiptOpened => "Opened the receipt of {$subject}.",
            AuditAction::OrderWalkInCreated, AuditAction::BookingWalkInCreated => "Made walk-in {$subject}".(isset($meta['customer']) ? ' for '.$meta['customer'] : '').(isset($meta['price_cents']) ? ' ('.$money('price_cents').')' : '').'.',
            AuditAction::OrderStatusChanged, AuditAction::BookingStatusChanged => "Moved {$subject} from ".$status('from').' to '.$status('to').(isset($meta['price_cents']) ? ', confirmed price '.$money('price_cents') : '').'.'.$reason,
            AuditAction::OrderPriceChanged, AuditAction::BookingPriceChanged => "Changed the confirmed price of {$subject} from ".($money('old_cents') ?? 'none').' to '.$money('new_cents').'.'.$reason,
            AuditAction::OrderCancelled, AuditAction::BookingCancelled => "Cancelled {$subject}.".$reason,
            AuditAction::OrderRejected, AuditAction::BookingRejected => "Rejected {$subject}.".$reason,
            AuditAction::OrderCompleted => "Released {$subject} (Completed).",
            AuditAction::BookingCompleted => "Completed {$subject}.",
            AuditAction::WalkInCustomerCreated => "Added walk-in customer {$subject}.",
            AuditAction::HoursChanged => 'Changed the opening hours.',
            AuditAction::ScheduleRulesChanged => 'Changed the booking rules ('.implode(', ', array_keys((array) ($meta['changes'] ?? []))).').',
            AuditAction::DateBlocked => 'Blocked '.implode(', ', (array) ($meta['dates'] ?? [$subject])).'.'.$reason,
            AuditAction::DateUnblocked => 'Unblocked '.($meta['date'] ?? $subject).'.',
            AuditAction::CatalogCreated => 'Added '.($meta['kind'] ?? 'item')." {$subject}.",
            AuditAction::CatalogUpdated => 'Edited '.($meta['kind'] ?? 'item')." {$subject}.",
            AuditAction::CatalogPublished => 'Published '.($meta['kind'] ?? 'item')." {$subject}.",
            AuditAction::CatalogUnpublished => 'Hid '.($meta['kind'] ?? 'item')." {$subject}.",
            AuditAction::CatalogDeleted => 'Deleted '.($meta['kind'] ?? 'item')." {$subject}.",
            AuditAction::FaqCreated => "Added the FAQ \"{$subject}\".",
            AuditAction::FaqUpdated => "Edited the FAQ \"{$subject}\".",
            AuditAction::FaqPublished => "Published the FAQ \"{$subject}\".",
            AuditAction::FaqUnpublished => "Hid the FAQ \"{$subject}\".",
            AuditAction::FaqDeleted => "Deleted the FAQ \"{$subject}\".",
            AuditAction::ReportCsvExported => 'Exported the income CSV ('.self::period($meta).', '.($meta['rows'] ?? 0).' rows).',
            AuditAction::EndOfDayOpened => 'Opened the end-of-day summary of '.($meta['date'] ?? '?').'.',
            AuditAction::AuditExported => 'Exported the activity log ('.self::period($meta).', '.($meta['rows'] ?? 0).' rows).',
            AuditAction::AuditPruned => 'Pruned activity up to row '.($meta['pruned_up_to_id'] ?? '?').' ('.($meta['rows'] ?? 0).' rows).',
            AuditAction::LoggedIn => 'Signed in.',
            AuditAction::LoginFailed => 'A sign-in to the owner account failed.',
            AuditAction::PasswordChanged => 'Changed the account password.',
            AuditAction::EmailChanged => 'Changed the account email.',
            AuditAction::SessionsSignedOut => 'Signed out '.($meta['count'] ?? 0).' other '.(($meta['count'] ?? 0) === 1 ? 'device' : 'devices').'.',
            AuditAction::SettingsChanged => 'Changed settings: '.implode(', ', (array) ($meta['keys'] ?? [])).'.',
        };

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @param array<string, mixed> $meta */
    private static function period(array $meta): string
    {
        $from = $meta['from'] ?? null;
        $to = $meta['to'] ?? null;

        return match (true) {
            $from === null && $to === null => 'all dates',
            $from === $to => (string) $from,
            default => ($from ?? 'start').' to '.($to ?? 'today'),
        };
    }
}
