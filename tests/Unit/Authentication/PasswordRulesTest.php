<?php

declare(strict_types=1);

namespace Tests\Unit\Authentication;

use App\Services\Authentication\SafePasswordRule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PasswordRulesTest extends TestCase
{
    #[Test]
    public function it_rejects_local_common_passwords(): void
    {
        $messages = [];
        $rule = new SafePasswordRule;

        $rule->validate('password', 'password123456', function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->assertSame(['The password is too common.'], $messages);
    }

    #[Test]
    public function it_accepts_a_long_non_common_password_in_tests(): void
    {
        $messages = [];
        $rule = new SafePasswordRule;

        $rule->validate('password', 'correct horse battery staple', function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->assertSame([], $messages);
    }
}
