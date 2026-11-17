<?php

namespace App\Notifications;

use App\Support\ShopSettings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Phase 10C: the "verify your email" message, in the shop's name. Laravel's own signed link (valid for
 * config auth.verification.expire minutes, 60), a clear button and the plain-text part Laravel adds to every mail.
 */
class VerifyEmailNotification extends VerifyEmail
{
    protected function buildMailMessage($url): MailMessage
    {
        $shop = app(ShopSettings::class)->shopName() ?? (string) config('app.name');
        $minutes = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject("Verify your email for {$shop}")
            ->greeting('Welcome!')
            ->line("Please confirm your email address to start using your {$shop} account: printing orders, photoshoot bookings and messages.")
            ->action('Verify my email', $url)
            ->line("This link works for {$minutes} minutes.")
            ->line('If you did not create an account, ignore this email.')
            ->salutation($shop);
    }
}
