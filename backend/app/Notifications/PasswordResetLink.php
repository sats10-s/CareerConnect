<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "forgot password" email. Sent to a student's personal email (they
 * have no college email) or a staff member's email, through whatever mail
 * driver is configured - the log driver in development.
 */
class PasswordResetLink extends Notification
{
    use Queueable;

    public function __construct(private readonly string $url, private readonly string $name) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your CareerConnect password')
            ->greeting("Hello {$this->name},")
            ->line('We received a request to reset your CareerConnect password.')
            ->action('Choose a new password', $this->url)
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you did not ask for this, you can ignore this email - your password stays the same.');
    }
}
