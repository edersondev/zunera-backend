<?php

declare(strict_types=1);

namespace Tests\Unit\Transfers;

use App\Models\Transfer;
use App\Models\User;
use App\Services\Transfers\TransferIdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TransferIdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_claims_a_key_before_executing_its_mutation(): void
    {
        $user = User::factory()->create();
        $transfer = Transfer::factory()->withUser($user)->create();

        $result = app(TransferIdempotencyService::class)->execute(
            (int) $user->id,
            'claimed-before-mutation',
            'create',
            'fingerprint',
            function () use ($user, $transfer): array {
                self::assertDatabaseHas('transfer_mutation_requests', [
                    'user_id' => $user->id,
                    'idempotency_key' => 'claimed-before-mutation',
                    'transfer_id' => null,
                    'completed_at' => null,
                ]);

                return ['transfer_id' => $transfer->id, 'status' => 201];
            },
        );

        self::assertFalse($result['replayed']);
        self::assertDatabaseHas('transfer_mutation_requests', [
            'user_id' => $user->id,
            'idempotency_key' => 'claimed-before-mutation',
            'transfer_id' => $transfer->id,
            'response_status' => 201,
        ]);
    }
}
