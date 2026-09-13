<?php

declare(strict_types=1);

namespace Tests\Feature\Transfers;

use App\Models\FinancialAccount;
use App\Models\Transfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class FilterAndSearchTransfersTest extends TransferFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function date_side_and_status_filters_combine_with_and_semantics(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user);
        $third = FinancialAccount::factory()->create(['user_id' => $user->id]);

        $match = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'status' => 'effective',
            'transfer_date' => '2026-09-10',
        ]);
        Transfer::factory()->pending()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'transfer_date' => '2026-09-10',
        ]);
        Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $third->id,
            'transfer_date' => '2026-09-10',
        ]);
        Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'transfer_date' => '2026-08-01',
        ]);

        $this->getJson("/api/v1/transfers?from=10/09/2026&to=2026-09-10&source_financial_account_id={$source->id}&destination_financial_account_id={$destination->id}&status=effective")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $match->id);

        $this->getJson("/api/v1/transfers?destination_financial_account_id={$destination->id}&status=pending")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function archived_owned_accounts_filter_history_while_foreign_accounts_are_hidden(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user);
        $archived = FinancialAccount::factory()->archived()->create(['user_id' => $user->id]);
        $foreign = FinancialAccount::factory()->create();

        Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $archived->id,
            'destination_financial_account_id' => $destination->id,
        ]);
        Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $archived->id,
        ]);

        $this->getJson("/api/v1/transfers?source_financial_account_id={$archived->id}")
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/transfers?destination_financial_account_id={$archived->id}")
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/transfers?source_financial_account_id={$foreign->id}")->assertNotFound();
        $this->getJson("/api/v1/transfers?destination_financial_account_id={$foreign->id}")->assertNotFound();
    }

    #[Test]
    public function text_search_ignores_case_and_accents_across_description_and_notes(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user);

        $descriptionMatch = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'description' => 'Transferência para reserva',
            'notes' => null,
        ]);
        $notesMatch = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'description' => 'Aporte mensal',
            'notes' => 'Transferência programada',
        ]);
        Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'description' => 'Pagamento',
            'notes' => null,
        ]);

        $response = $this->getJson('/api/v1/transfers?q=TRANSFERENCIA')->assertOk()->assertJsonPath('meta.total', 2);
        $ids = array_column($response->json('data'), 'id');
        self::assertContains($descriptionMatch->id, $ids);
        self::assertContains($notesMatch->id, $ids);
    }
}
