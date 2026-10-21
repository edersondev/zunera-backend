<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;

final class RecurringCardDeletedPurchaseTest extends RecurringTransactionFeatureTestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function deleting_recorded_card_purchase_marks_occurrence_without_regenerating_it(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $rule = $this->cardRule($user, $card, [
            'start_date' => '2026-08-10',
            'eligibility_starts_on' => '2026-08-10',
            'schedule_cursor' => '2026-08-10',
            'end_date' => '2026-09-10',
        ]);
        self::assertSame(2, app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24'));
        $occurrences = $rule->cardOccurrences()->orderBy('scheduled_date')->get();
        $first = $occurrences[0];
        $second = $occurrences[1];
        $purchaseId = $first->purchase->id;
        $siblingPurchaseId = $second->purchase->id;

        $this->deleteJson("/api/v1/credit-card-purchases/{$purchaseId}", [], ['Idempotency-Key' => 'delete-recurring-purchase'])
            ->assertOk();

        $listed = $this->getJson("/api/v1/recurring-transactions/{$rule->id}/occurrences")->assertOk();
        $deletedOccurrence = collect($listed->json('data'))->firstWhere('id', $first->id);
        $siblingOccurrence = collect($listed->json('data'))->firstWhere('id', $second->id);
        self::assertSame('recorded', $deletedOccurrence['state']);
        self::assertNull($deletedOccurrence['purchase_id']);
        self::assertNotNull($deletedOccurrence['purchase_deleted_at']);
        self::assertSame($siblingPurchaseId, $siblingOccurrence['purchase_id']);
        self::assertNotNull($first->refresh()->purchase_deleted_at);
        self::assertNull($second->refresh()->purchase_deleted_at);
        self::assertSame(0, app(RecurringOccurrenceService::class)->processRule($rule->id, '2026-09-24'));
        self::assertNull($first->fresh()->purchase);
        self::assertSame($siblingPurchaseId, $second->fresh()->purchase->id);
    }
}
