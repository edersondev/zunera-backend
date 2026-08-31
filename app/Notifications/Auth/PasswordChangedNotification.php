<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

final class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $messageId;

    public function __construct()
    {
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
        return (new MailMessage)
            ->subject('Your Zunera password changed')
            ->line('Your Zunera password was changed.')
            ->line('If this was not you, request password recovery immediately.')
            ->withSymfonyMessage(function (Email $message): void {
                $message->getHeaders()->addTextHeader('X-Zunera-Message-ID', $this->messageId);
            });
    }
}
