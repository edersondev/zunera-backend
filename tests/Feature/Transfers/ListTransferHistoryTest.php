<?php

declare(strict_types=1);

namespace Tests\Feature\Transfers;

use App\Models\Transfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class ListTransferHistoryTest extends TransferFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function history_is_newest_first_with_totals_and_bounded_page_size(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user);

        $older = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'transfer_date' => today()->subWeek(),
        ]);
        $newer = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'transfer_date' => today(),
        ]);
        $sameDateLater = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'transfer_date' => today(),
        ]);

        $this->getJson('/api/v1/transfers?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $sameDateLater->id)
            ->assertJsonPath('data.1.id', $newer->id)
            ->assertJsonPath('data.2.id', $older->id);

        $this->getJson('/api/v1/transfers?per_page=51')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/transfers?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);
    }

    #[Test]
    public function active_and_removed_views_are_owner_scoped(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user);

        $active = Transfer::factory()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
        ]);
        $removed = Transfer::factory()->removed()->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
        ]);
        $foreign = Transfer::factory()->create();

        $this->getJson('/api/v1/transfers')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $active->id);
        $this->getJson('/api/v1/transfers?view=removed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $removed->id);
        self::assertNotSame($foreign->id, $active->id);
    }

    #[Test]
    public function guest_cannot_list_transfers(): void
    {
        $this->getJson('/api/v1/transfers')->assertUnauthorized();
    }

    #[Test]
    public function invalid_date_ranges_are_rejected(): void
    {
        $this->signInUser();

        $this->getJson('/api/v1/transfers?from=2026-09-10&to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/transfers?from=01/09/2026&to=10/09/2026')->assertOk();
    }
}
