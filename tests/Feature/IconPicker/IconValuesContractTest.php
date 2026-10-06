<?php

declare(strict_types=1);

namespace Tests\Feature\IconPicker;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IconValuesContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function financial_accounts_keep_existing_values_and_accept_new_curated_icons(): void
    {
        $this->signIn();
        $account = $this->postJson('/api/v1/financial-accounts', [
            'name' => 'Reserva',
            'account_type' => 'savings',
            'icon' => 'piggy_bank',
            'initial_balance_centavos' => 0,
        ])->assertCreated()->assertJsonPath('data.icon', 'piggy_bank')->json('data.id');

        $this->patchJson("/api/v1/financial-accounts/{$account}", ['icon' => 'cash'])
            ->assertOk()->assertJsonPath('data.icon', 'cash');
        $this->getJson("/api/v1/financial-accounts/{$account}")
            ->assertOk()->assertJsonPath('data.icon', 'cash');
        $this->patchJson("/api/v1/financial-accounts/{$account}", ['icon' => 'unknown'])
            ->assertUnprocessable()->assertJsonValidationErrors(['icon']);
    }

    #[Test]
    public function categories_keep_existing_values_and_accept_new_curated_icons(): void
    {
        $this->signIn();
        $category = $this->postJson('/api/v1/categories', [
            'name' => 'Viagem',
            'classification' => 'expense',
            'icon' => 'gamepad',
        ])->assertCreated()->assertJsonPath('data.icon', 'gamepad')->json('data.id');

        $this->patchJson("/api/v1/categories/{$category}", ['icon' => 'travel'])
            ->assertOk()->assertJsonPath('data.icon', 'travel');
        $this->getJson("/api/v1/categories/{$category}")
            ->assertOk()->assertJsonPath('data.icon', 'travel');
        $this->patchJson("/api/v1/categories/{$category}", ['icon' => 'unknown'])
            ->assertUnprocessable()->assertJsonValidationErrors(['icon']);
    }

    #[Test]
    public function credit_cards_keep_existing_values_and_accept_new_curated_icons(): void
    {
        $this->signIn();
        $card = $this->postJson('/api/v1/credit-cards', [
            'name' => 'Cartão',
            'institution_name' => 'Banco',
            'last_four' => '1234',
            'credit_limit_centavos' => 100_000,
            'closing_day' => 10,
            'due_day' => 17,
            'icon' => 'credit_card',
        ], ['Idempotency-Key' => 'icon-picker-card-create'])
            ->assertCreated()->assertJsonPath('data.icon', 'credit_card')->json('data.id');

        $this->patchJson("/api/v1/credit-cards/{$card}", ['icon' => 'travel'], ['Idempotency-Key' => 'icon-picker-card-update'])
            ->assertOk()->assertJsonPath('data.icon', 'travel');
        $this->getJson("/api/v1/credit-cards/{$card}")
            ->assertOk()->assertJsonPath('data.icon', 'travel');
        $this->patchJson("/api/v1/credit-cards/{$card}", ['icon' => 'unknown'], ['Idempotency-Key' => 'icon-picker-card-invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['icon']);
    }

    private function signIn(): void
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }
}
