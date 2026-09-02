<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

final class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $messageId;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $token,
    ) {
        $this->messageId = Str::uuid()->toString();
        $this->onQueue((string) config('authentication.mail.queue', 'auth-mail'));
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/')
            .'/reset-password?token='.urlencode($this->token)
            .'&email='.urlencode((string) $notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Reset your Zunera password')
            ->line('Use this secure link to reset your Zunera password.')
            ->action('Reset password', $url)
            ->line('This password reset link expires in 60 minutes.')
            ->line('If you did not request this, no action is required.')
            ->withSymfonyMessage(function (Email $message): void {
                $message->getHeaders()->addTextHeader('X-Zunera-Message-ID', $this->messageId);
            });
    }
}
