<?php

declare(strict_types=1);

namespace Tests\Feature\Transfers;

use App\Models\FinancialAccount;
use App\Models\Transfer;
use App\Models\TransferMutationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class RecordTransfersTest extends TransferFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_records_an_effective_transfer_once_per_idempotency_key(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user);

        $created = $this->postJson('/api/v1/transfers', $this->payload($source, $destination), ['Idempotency-Key' => 'transfer-1'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'effective')
            ->assertJsonPath('data.amount_centavos', 100_000)
            ->assertJsonPath('data.source_financial_account.id', $source->id)
            ->assertJsonPath('data.destination_financial_account.id', $destination->id)
            ->assertJsonPath('data.currency_code', 'BRL');

        $this->postJson('/api/v1/transfers', $this->payload($source, $destination), ['Idempotency-Key' => 'transfer-1'])
            ->assertCreated()
            ->assertJsonPath('data.id', $created->json('data.id'));

        self::assertSame(1, Transfer::count());
        self::assertSame(400_000, $source->refresh()->current_balance_centavos);
        self::assertSame(300_000, $destination->refresh()->current_balance_centavos);
        self::assertSame(700_000, $source->current_balance_centavos + $destination->current_balance_centavos);
    }

    #[Test]
    public function a_future_transfer_defaults_to_pending_without_reserving_funds(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user);
        $future = today()->addDays(10)->toDateString();

        $this->postJson('/api/v1/transfers', $this->payload($source, $destination, overrides: ['transfer_date' => $future]), ['Idempotency-Key' => 'future-1'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        self::assertSame(500_000, $source->refresh()->current_balance_centavos);
        self::assertSame(200_000, $destination->refresh()->current_balance_centavos);

        $this->postJson('/api/v1/transfers', $this->payload($source, $destination, overrides: ['transfer_date' => $future, 'status' => 'effective']), ['Idempotency-Key' => 'future-2'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'effective_future_date');

        self::assertSame(1, Transfer::count());
        self::assertSame(500_000, $source->refresh()->current_balance_centavos);
    }

    #[Test]
    public function invalid_amounts_dates_and_text_have_safe_field_feedback(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user);

        $this->postJson('/api/v1/transfers', $this->payload($source, $destination, 0), ['Idempotency-Key' => 'bad-amount'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount_centavos');
        $this->postJson('/api/v1/transfers', $this->payload($source, $destination, 99_999_999_999 + 1), ['Idempotency-Key' => 'huge-amount'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount_centavos');
        $this->postJson('/api/v1/transfers', $this->payload($source, $destination, overrides: ['transfer_date' => '1899-12-31']), ['Idempotency-Key' => 'old-date'])
            ->assertUnprocessable()->assertJsonValidationErrors('transfer_date');
        $this->postJson('/api/v1/transfers', $this->payload($source, $destination, overrides: ['transfer_date' => 'not-a-date']), ['Idempotency-Key' => 'junk-date'])
            ->assertUnprocessable()->assertJsonValidationErrors('transfer_date');
        $this->postJson('/api/v1/transfers', $this->payload($source, $destination, overrides: ['description' => str_repeat('a', 201)]), ['Idempotency-Key' => 'long-description'])
            ->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->postJson('/api/v1/transfers', $this->payload($source, $destination), [])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        self::assertSame(0, Transfer::count());
    }

    #[Test]
    public function same_side_archived_and_foreign_accounts_are_denied(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user);
        $archived = FinancialAccount::factory()->archived()->create(['user_id' => $user->id]);
        $foreign = FinancialAccount::factory()->create();

        $this->postJson('/api/v1/transfers', $this->payload($source, $source), ['Idempotency-Key' => 'same-side'])
            ->assertUnprocessable()->assertJsonValidationErrors('destination_financial_account_id');
        $this->postJson('/api/v1/transfers', $this->payload($archived, $destination), ['Idempotency-Key' => 'archived-source'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_financial_account_id');
        $this->postJson('/api/v1/transfers', $this->payload($foreign, $destination), ['Idempotency-Key' => 'foreign-source'])
            ->assertNotFound();
        $this->postJson('/api/v1/transfers', $this->payload($source, $foreign), ['Idempotency-Key' => 'foreign-destination'])
            ->assertNotFound();

        self::assertSame(0, Transfer::count());
        self::assertSame(500_000, $source->refresh()->current_balance_centavos);
        self::assertSame(200_000, $destination->refresh()->current_balance_centavos);
        self::assertSame(0, $archived->refresh()->current_balance_centavos);
    }

    #[Test]
    public function effective_transfer_that_overdraws_its_source_changes_nothing(): void
    {
        $user = $this->signInUser();
        [$source, $destination] = $this->ownedSides($user, 50_000, 200_000);

        $this->postJson('/api/v1/transfers', $this->payload($source, $destination, 60_000), ['Idempotency-Key' => 'overdraft'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_source_balance');

        self::assertSame(0, Transfer::count());
        self::assertSame(0, TransferMutationRequest::count());
        self::assertSame(50_000, $source->refresh()->current_balance_centavos);
        self::assertSame(200_000, $destination->refresh()->current_balance_centavos);
    }

    #[Test]
    public function guest_cannot_record_a_transfer(): void
    {
        $this->postJson('/api/v1/transfers', [], ['Idempotency-Key' => 'guest'])->assertUnauthorized();
    }
}
