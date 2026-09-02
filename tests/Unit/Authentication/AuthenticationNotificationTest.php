<?php

declare(strict_types=1);

namespace Tests\Unit\Authentication;

use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class AuthenticationNotificationTest extends TestCase
{
    #[Test]
    public function reset_password_notification_uses_lowercase_uuid_message_id_header(): void
    {
        $notification = new ResetPasswordNotification('secret-token');
        $message = $notification->toMail(new class
        {
            public function getEmailForPasswordReset(): string
            {
                return 'person@example.com';
            }
        });
        $email = new Email;

        foreach ($message->callbacks as $callback) {
            $callback($email);
        }

        $messageId = $email->getHeaders()->get('X-Zunera-Message-ID')?->getBodyAsString();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $messageId);
    }

    #[Test]
    public function password_changed_notification_uses_safe_subject(): void
    {
        $message = (new PasswordChangedNotification)->toMail(new \stdClass);

        $this->assertSame('Your Zunera password changed', $message->subject);
    }
}
