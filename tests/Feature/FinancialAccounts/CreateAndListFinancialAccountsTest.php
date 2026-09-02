<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialAccounts;

use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CreateAndListFinancialAccountsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_user_creates_an_active_account_with_defaults(): void
    {
        $user = $this->signedInUser();

        $response = $this->postJson('/api/v1/financial-accounts', [
            'name' => '  Conta Principal ',
            'account_type' => 'checking',
            'institution_name' => 'Nubank',
            'color' => null,
            'icon' => null,
            'initial_balance_centavos' => 125_050,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Conta Principal')
            ->assertJsonPath('data.institution_name', 'Nubank')
            ->assertJsonPath('data.color', 'teal')
            ->assertJsonPath('data.icon', 'circle')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.current_balance_centavos', 125_050);

        $this->assertDatabaseHas('financial_accounts', [
            'user_id' => $user->id,
            'normalized_name' => 'conta principal',
            'institution_name' => 'Nubank',
            'initial_balance_centavos' => 125_050,
            'current_balance_centavos' => 125_050,
        ]);
    }

    #[Test]
    public function list_returns_active_accounts_by_default_and_summary_sums_active_only(): void
    {
        $user = $this->signedInUser();

        $this->createAccount($user, 'Conta corrente', 100_000);
        $this->createAccount($user, 'Poupança', 50);
        FinancialAccount::factory()->archived('Reserva antiga')->create([
            'user_id' => $user->id,
            'initial_balance_centavos' => 9_999,
            'current_balance_centavos' => 9_999,
        ]);

        $this->getJson('/api/v1/financial-accounts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.archived_at', null);

        $this->getJson('/api/v1/financial-accounts/summary')
            ->assertOk()
            ->assertJsonPath('data.active_account_count', 2)
            ->assertJsonPath('data.active_combined_balance_centavos', 100_050)
            ->assertJsonPath('data.currency_code', 'BRL');

        $this->getJson('/api/v1/financial-accounts?status=archived')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Reserva antiga');
    }

    #[Test]
    public function summary_route_resolves_before_account_id_lookup(): void
    {
        $user = $this->signedInUser();

        $this->getJson('/api/v1/financial-accounts/summary')
            ->assertOk()
            ->assertJsonPath('data.active_account_count', 0)
            ->assertJsonPath('data.active_combined_balance_centavos', 0);
    }

    #[Test]
    public function duplicate_active_names_are_rejected_after_normalization(): void
    {
        $user = $this->signedInUser();
        $this->createAccount($user, 'Nu Salário');

        $this->postJson('/api/v1/financial-accounts', [
            'name' => ' NU   SALÁRIO ',
            'account_type' => 'digital',
            'initial_balance_centavos' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function repeated_create_submission_does_not_create_duplicates(): void
    {
        $user = $this->signedInUser();
        $payload = [
            'name' => 'Conta principal',
            'account_type' => 'checking',
            'initial_balance_centavos' => 1_000,
        ];

        $this->postJson('/api/v1/financial-accounts', $payload)->assertCreated();
        $this->postJson('/api/v1/financial-accounts', $payload)->assertUnprocessable();

        $this->assertSame(1, FinancialAccount::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function database_layer_rejects_concurrent_active_name_duplicates(): void
    {
        $user = $this->signedInUser();
        $this->createAccount($user, 'Conta única');

        $this->expectException(QueryException::class);

        FinancialAccount::query()->create([
            'user_id' => $user->id,
            'name' => 'CONTA ÚNICA',
            'account_type' => 'checking',
            'initial_balance_centavos' => 0,
            'current_balance_centavos' => 0,
            'currency_code' => 'BRL',
            'status' => 'active',
            'has_financial_movements' => false,
        ]);
    }

    #[Test]
    public function invalid_account_values_return_actionable_validation_feedback(): void
    {
        $this->signedInUser();

        $this->postJson('/api/v1/financial-accounts', [
            'name' => '   ',
            'account_type' => 'time_deposit',
            'color' => 'neon',
            'initial_balance_centavos' => 1_000_000_000_000,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'account_type', 'color', 'initial_balance_centavos']);
    }

    #[Test]
    public function unauthenticated_requests_are_denied(): void
    {
        $this->getJson('/api/v1/financial-accounts')->assertUnauthorized();
        $this->postJson('/api/v1/financial-accounts', [
            'name' => 'Conta',
            'account_type' => 'checking',
            'initial_balance_centavos' => 0,
        ])->assertUnauthorized();
    }

    #[Test]
    public function accounts_are_invisible_to_other_users(): void
    {
        $owner = User::factory()->create();
        $account = $this->createAccount($owner, 'Conta privada', 0);
        $this->signedInUser();

        $this->getJson("/api/v1/financial-accounts/{$account->id}")
            ->assertNotFound();

        $this->getJson('/api/v1/financial-accounts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function signedInUser(): User
    {
        $user = User::factory()->create();

        return $this->signIn($user);
    }

    private function signIn(User $user): User
    {
        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        return $user;
    }

    private function fromFrontend(): self
    {
        return $this
            ->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173');
    }

    private function createAccount(User $user, string $name, int $initialBalanceCentavos = 0): FinancialAccount
    {
        return FinancialAccount::factory()->create([
            'user_id' => $user->id,
            'name' => $name,
            'initial_balance_centavos' => $initialBalanceCentavos,
            'current_balance_centavos' => $initialBalanceCentavos,
        ]);
    }
}
