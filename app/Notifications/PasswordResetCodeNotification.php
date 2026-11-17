<?php

namespace App\Notifications;

use App\Support\ShopSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Phase 10C: the 6-digit password reset code. The code is only in this email; the database keeps its hash. */
class PasswordResetCodeNotification extends Notification
{
    public function __construct(private readonly string $code, private readonly int $minutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $shop = app(ShopSettings::class)->shopName() ?? (string) config('app.name');

        return (new MailMessage)
            ->subject("Your {$shop} password reset code")
            ->greeting('Hello!')
            ->line('Use this code to choose a new password:')
            ->line('**'.$this->code.'**')
            ->line("It works for {$this->minutes} minutes and only once. Never share it: the shop will never ask you for it.")
            ->line('If you did not ask to reset your password, ignore this email. Your password stays the same.')
            ->salutation($shop);
    }

    /** For tests only: the code that was sent. */
    public function code(): string
    {
        return $this->code;
    }
}
