<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialAccounts;

use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ViewAndUpdateFinancialAccountsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_can_read_full_account_details(): void
    {
        $user = $this->signedInUser();
        $account = FinancialAccount::factory()->create([
            'user_id' => $user->id,
            'name' => 'Conta corrente',
            'account_type' => 'checking',
            'institution_name' => 'Nubank',
            'color' => 'violet',
            'icon' => 'wallet',
            'initial_balance_centavos' => 5_000,
            'current_balance_centavos' => 5_000,
        ]);

        $this->getJson("/api/v1/financial-accounts/{$account->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $account->id)
            ->assertJsonPath('data.name', 'Conta corrente')
            ->assertJsonPath('data.account_type', 'checking')
            ->assertJsonPath('data.institution_name', 'Nubank')
            ->assertJsonPath('data.color', 'violet')
            ->assertJsonPath('data.icon', 'wallet')
            ->assertJsonPath('data.initial_balance_centavos', 5_000)
            ->assertJsonPath('data.current_balance_centavos', 5_000);
    }

    #[Test]
    public function owner_can_update_editable_fields(): void
    {
        $user = $this->signedInUser();
        $account = FinancialAccount::factory()->create([
            'user_id' => $user->id,
            'name' => 'Conta antiga',
            'account_type' => 'checking',
            'institution_name' => 'Banco do Brasil',
            'initial_balance_centavos' => 1_000,
            'current_balance_centavos' => 1_000,
        ]);

        $this->patchJson("/api/v1/financial-accounts/{$account->id}", [
            'name' => 'Conta nova',
            'account_type' => 'savings',
            'institution_name' => null,
            'color' => null,
            'icon' => 'bank',
            'initial_balance_centavos' => 2_500,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Conta nova')
            ->assertJsonPath('data.account_type', 'savings')
            ->assertJsonPath('data.institution_name', null)
            ->assertJsonPath('data.color', 'teal')
            ->assertJsonPath('data.icon', 'bank')
            ->assertJsonPath('data.initial_balance_centavos', 2_500)
            ->assertJsonPath('data.current_balance_centavos', 2_500);

        $this->assertDatabaseHas('financial_accounts', [
            'id' => $account->id,
            'normalized_name' => 'conta nova',
            'institution_name' => null,
            'color' => 'teal',
            'icon' => 'bank',
        ]);
    }

    #[Test]
    public function renaming_to_an_existing_active_name_returns_state_conflict(): void
    {
        $user = $this->signedInUser();
        $this->createAccount($user, 'Conta principal');
        $second = $this->createAccount($user, 'Outra conta');

        $this->patchJson("/api/v1/financial-accounts/{$second->id}", [
            'name' => ' CONTA PRINCIPAL ',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'account_name_conflict');
    }

    #[Test]
    public function invalid_updates_leave_account_unchanged(): void
    {
        $user = $this->signedInUser();
        $account = $this->createAccount($user, 'Conta estável', 1_000);

        $this->patchJson("/api/v1/financial-accounts/{$account->id}", [
            'name' => '',
            'account_type' => 'invalid',
            'initial_balance_centavos' => 1_000_000_000_000,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'account_type', 'initial_balance_centavos']);

        $this->assertDatabaseHas('financial_accounts', [
            'id' => $account->id,
            'name' => 'Conta estável',
            'initial_balance_centavos' => 1_000,
        ]);
    }

    #[Test]
    public function initial_balance_is_locked_after_financial_movements_exist(): void
    {
        $user = $this->signedInUser();
        $account = FinancialAccount::factory()->withMovements()->create([
            'user_id' => $user->id,
            'initial_balance_centavos' => 1_000,
            'current_balance_centavos' => 500,
        ]);

        $this->patchJson("/api/v1/financial-accounts/{$account->id}", [
            'initial_balance_centavos' => 2_000,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'initial_balance_locked');
    }

    #[Test]
    public function another_users_account_is_not_visible_or_editable(): void
    {
        $owner = User::factory()->create();
        $account = FinancialAccount::factory()->create(['user_id' => $owner->id]);
        $this->signedInUser();

        $this->getJson("/api/v1/financial-accounts/{$account->id}")->assertNotFound();
        $this->patchJson("/api/v1/financial-accounts/{$account->id}", ['name' => 'Invadida'])
            ->assertNotFound();

        $this->assertDatabaseHas('financial_accounts', [
            'id' => $account->id,
            'name' => $account->name,
        ]);
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
