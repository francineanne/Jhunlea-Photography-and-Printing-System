<?php

namespace App\Notifications;

use App\Support\ShopSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Phase 10C: sent after a password reset with a code, so the owner of the email knows. Never holds the password. */
class PasswordChangedNotification extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $shop = app(ShopSettings::class)->shopName() ?? (string) config('app.name');

        return (new MailMessage)
            ->subject("Your {$shop} password was changed")
            ->greeting('Hello!')
            ->line('The password of your account was just changed, and every device that was signed in has been signed out.')
            ->line('If this was you, there is nothing else to do.')
            ->line('If it was not you, reset your password again right away and tell the shop.')
            ->action('Log in', route('login'))
            ->salutation($shop);
    }
}
