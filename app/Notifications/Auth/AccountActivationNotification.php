<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

final class AccountActivationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $messageId;

    public function __construct(#[\SensitiveParameter] private readonly string $token)
    {
        $this->messageId = Str::uuid()->toString();
        $this->onQueue((string) config('authentication.mail.queue', 'auth-mail'));
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/activate-account?email='.urlencode((string) $notifiable->email)
            .'&token='.urlencode($this->token);

        return (new MailMessage)
            ->subject(__('auth.activation_subject'))
            ->greeting(__('auth.activation_greeting', ['name' => $notifiable->name]))
            ->line(__('auth.activation_intro'))
            ->action(__('auth.activation_action'), $url)
            ->line(__('auth.activation_expiry'))
            ->line(__('auth.activation_ignore'))
            ->salutation(__('auth.activation_salutation'))
            ->withSymfonyMessage(function (Email $message): void {
                $message->getHeaders()->addTextHeader('X-Zunera-Message-ID', $this->messageId);
            });
    }
}
