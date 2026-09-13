<?php

declare(strict_types=1);

namespace Tests\Feature\Transfers;

use App\Models\FinancialAccount;
use App\Models\Transfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class ViewTransferDetailsTest extends TransferFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function detail_embeds_both_sides_including_archived_history(): void
    {
        $user = $this->signInUser();
        $archivedSource = FinancialAccount::factory()->archived()->create(['user_id' => $user->id, 'name' => 'Conta antiga']);
        $destination = FinancialAccount::factory()->create(['user_id' => $user->id, 'name' => 'Conta nova']);
        $transfer = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $archivedSource->id,
            'destination_financial_account_id' => $destination->id,
            'amount_centavos' => 12_345,
            'description' => 'Transferência histórica',
        ]);

        $this->getJson("/api/v1/transfers/{$transfer->id}")
            ->assertOk()
            ->assertJsonPath('data.source_financial_account.name', 'Conta antiga')
            ->assertJsonPath('data.source_financial_account.status', 'archived')
            ->assertJsonPath('data.destination_financial_account.name', 'Conta nova')
            ->assertJsonPath('data.amount_centavos', 12_345)
            ->assertJsonPath('data.description', 'Transferência histórica');
    }

    #[Test]
    public function foreign_or_missing_transfers_are_not_disclosed(): void
    {
        $this->signInUser();
        $foreign = Transfer::factory()->create();

        $this->getJson("/api/v1/transfers/{$foreign->id}")->assertNotFound();
        $this->getJson('/api/v1/transfers/999999')->assertNotFound();
    }

    #[Test]
    public function guest_cannot_view_transfer_details(): void
    {
        $transfer = Transfer::factory()->create();

        $this->getJson("/api/v1/transfers/{$transfer->id}")->assertUnauthorized();
    }
}
