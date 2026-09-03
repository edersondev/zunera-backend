<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialAccounts;

use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ArchiveAndRestoreFinancialAccountsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_can_archive_an_active_account_and_view_archived_list(): void
    {
        $user = $this->signedInUser();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);

        $this->postJson("/api/v1/financial-accounts/{$account->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->assertDatabaseHas('financial_accounts', [
            'id' => $account->id,
            'status' => 'archived',
        ]);

        $this->getJson('/api/v1/financial-accounts?status=archived')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function archiving_last_active_account_empties_active_summary(): void
    {
        $user = $this->signedInUser();
        $account = FinancialAccount::factory()->create([
            'user_id' => $user->id,
            'initial_balance_centavos' => 3_000,
            'current_balance_centavos' => 3_000,
        ]);

        $this->postJson("/api/v1/financial-accounts/{$account->id}/archive")->assertOk();

        $this->getJson('/api/v1/financial-accounts/summary')
            ->assertOk()
            ->assertJsonPath('data.active_account_count', 0)
            ->assertJsonPath('data.active_combined_balance_centavos', 0);
    }

    #[Test]
    public function archived_history_remains_accessible_and_restore_returns_account_to_active(): void
    {
        $user = $this->signedInUser();
        $account = FinancialAccount::factory()->archived('Reserva')->create([
            'user_id' => $user->id,
            'institution_name' => 'Itaú',
            'initial_balance_centavos' => 4_000,
            'current_balance_centavos' => 4_000,
        ]);

        $this->getJson("/api/v1/financial-accounts/{$account->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.institution_name', 'Itaú');

        $this->postJson("/api/v1/financial-accounts/{$account->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.archived_at', null);

        $this->getJson('/api/v1/financial-accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function restore_with_duplicate_active_name_is_rejected_without_changing_state(): void
    {
        $user = $this->signedInUser();
        $this->createAccount($user, 'Conta principal');
        $archived = FinancialAccount::factory()->archived('CONTA PRINCIPAL')->create([
            'user_id' => $user->id,
        ]);

        $this->postJson("/api/v1/financial-accounts/{$archived->id}/restore")
            ->assertStatus(409)
            ->assertJsonPath('code', 'account_name_conflict');

        $this->assertDatabaseHas('financial_accounts', [
            'id' => $archived->id,
            'status' => 'archived',
        ]);
    }

    #[Test]
    public function repeated_lifecycle_actions_are_rejected_as_state_conflicts(): void
    {
        $user = $this->signedInUser();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);

        $this->postJson("/api/v1/financial-accounts/{$account->id}/archive")
            ->assertOk();
        $this->postJson("/api/v1/financial-accounts/{$account->id}/archive")
            ->assertStatus(409)
            ->assertJsonPath('code', 'account_already_archived');

        $this->postJson("/api/v1/financial-accounts/{$account->id}/restore")
            ->assertOk();
        $this->postJson("/api/v1/financial-accounts/{$account->id}/restore")
            ->assertStatus(409)
            ->assertJsonPath('code', 'account_already_active');
    }

    #[Test]
    public function another_user_cannot_archive_or_restore_the_account(): void
    {
        $owner = User::factory()->create();
        $account = FinancialAccount::factory()->create(['user_id' => $owner->id]);
        $this->signedInUser();

        $this->postJson("/api/v1/financial-accounts/{$account->id}/archive")->assertNotFound();
        $this->postJson("/api/v1/financial-accounts/{$account->id}/restore")->assertNotFound();

        $this->assertDatabaseHas('financial_accounts', [
            'id' => $account->id,
            'status' => 'active',
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
