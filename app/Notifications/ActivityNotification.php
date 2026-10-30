<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use Illuminate\Notifications\Notification;

/**
 * One in-app notification (Phase 5B), written to the database only: no email, SMS, push or queue. It stores small data
 * (see NotificationKind); the sentence is built when it is shown. Sent only by NotificationDispatcher.
 */
class ActivityNotification extends Notification
{
    /**
     * An order or booking notice, or (Phase 7B) a studio chat notice with only the conversation id and sender name.
     *
     * @param  array{kind: string, payable_type?: string, payable_id?: int, reference?: string, amount?: ?string, payment?: ?string, conversation_id?: int, sender_name?: string}  $data
     */
    public function __construct(public readonly array $data) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** The `type` column holds the kind, so the table can be read without loading this class. */
    public function databaseType(object $notifiable): string
    {
        return $this->data['kind'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }

    public function kind(): NotificationKind
    {
        return NotificationKind::from($this->data['kind']);
    }
}
