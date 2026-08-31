<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PasswordRecoveryRequestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_neutral_feedback_for_known_and_unknown_email(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'person@example.com']);

        $known = $this->postJson('/api/v1/auth/password/recovery', ['email' => 'person@example.com']);
        $unknown = $this->postJson('/api/v1/auth/password/recovery', ['email' => 'unknown@example.com']);

        $known->assertAccepted();
        $unknown->assertAccepted();
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }
}
