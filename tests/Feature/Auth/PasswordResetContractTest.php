<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PasswordResetContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_stable_expired_recovery_code_for_expired_existing_tokens(): void
    {
        User::factory()->create(['email' => 'person@example.com']);
        DB::table('password_reset_tokens')->insert([
            'email' => 'person@example.com',
            'token' => Hash::make('expired-token'),
            'created_at' => now()->subMinutes(61),
        ]);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'person@example.com',
            'token' => 'expired-token',
            'password' => 'new correct battery staple',
            'password_confirmation' => 'new correct battery staple',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'recovery_link_expired');
    }
}
