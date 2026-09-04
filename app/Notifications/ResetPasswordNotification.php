<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(public string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $url = $frontend.'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('AGRI-AKAP password reset')
            ->greeting('Password reset requested')
            ->line('A password reset was requested for your AGRI-AKAP account at the Municipal Agriculture Office of Echague.')
            ->action('Reset password', $url)
            ->line("This link expires in {$minutes} minutes and can be used only once.")
            ->line('If you did not request this reset, contact the MAO Administrator. No changes have been made to your account.');
    }
}
