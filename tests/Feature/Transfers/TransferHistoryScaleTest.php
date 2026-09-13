<?php

declare(strict_types=1);

namespace Tests\Feature\Transfers;

use App\Models\Transfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class TransferHistoryScaleTest extends TransferFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function five_thousand_transfers_are_progressively_reachable_within_the_first_batch_budget(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user);

        Transfer::factory()->count(5_000)->create([
            'user_id' => $user->id,
            'source_financial_account_id' => $source->id,
            'destination_financial_account_id' => $destination->id,
            'transfer_date' => today()->subDay(),
        ]);
        $needle = Transfer::query()->firstOrFail();
        $needle->description = 'Escala transferível';
        $needle->save();

        $startedAt = microtime(true);
        $firstBatch = $this->getJson('/api/v1/transfers?per_page=50')->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.total', 5_000)
            ->assertJsonPath('meta.last_page', 100);
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $firstIds = array_column($firstBatch->json('data'), 'id');
        self::assertSame($firstIds, array_values(array_unique($firstIds)), 'Same-date ordering must stay stable and unique.');
        self::assertSame(Transfer::query()->orderByDesc('id')->value('id'), $firstIds[0]);

        $this->getJson('/api/v1/transfers?per_page=50&page=100')->assertOk()->assertJsonCount(50, 'data');
        $this->getJson("/api/v1/transfers?q=escala&source_financial_account_id={$source->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $needle->id);

        self::assertLessThanOrEqual(2_000, $elapsedMs, "First batch took {$elapsedMs} ms against the two-second budget.");
    }
}
