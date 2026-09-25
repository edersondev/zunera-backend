<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class RecurringCardBillingParityTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('billingBoundaryCases')]
    public function automatic_and_manual_single_payment_purchases_have_identical_billing_effects(
        string $purchaseDate,
        int $closingDay,
        int $dueDay,
        int $amount,
    ): void {
        $user = $this->signInUser();
        $category = $this->ownedCategory($user);
        $recurringCard = $this->ownedCard($user);
        $manualCard = $this->ownedCard($user);
        foreach ([$recurringCard, $manualCard] as $card) {
            $card->update(['closing_day' => $closingDay, 'due_day' => $dueDay]);
        }

        $rule = $this->cardRule($user, $recurringCard, [
            'category_id' => $category->id,
            'amount_centavos' => $amount,
            'start_date' => $purchaseDate,
            'eligibility_starts_on' => $purchaseDate,
            'schedule_cursor' => $purchaseDate,
        ]);
        self::assertSame(1, app(RecurringOccurrenceService::class)->processRule($rule->id, $purchaseDate));
        $automatic = $rule->cardOccurrences()->firstOrFail()->purchase;
        self::assertNotNull($automatic);

        $manualResponse = $this->postJson('/api/v1/credit-cards/'.$manualCard->id.'/purchases', [
            'category_id' => $category->id,
            'description' => 'Compra manual equivalente',
            'purchase_date' => $purchaseDate,
            'total_amount_centavos' => $amount,
            'installment_count' => 1,
        ], ['Idempotency-Key' => 'manual-billing-parity'])->assertCreated();
        $manual = $manualCard->purchases()->firstOrFail();

        $automaticInstallment = $automatic->installments()->with('statement')->firstOrFail();
        $manualInstallment = $manual->installments()->with('statement')->firstOrFail();
        self::assertSame($manualInstallment->statement->closing_date->toDateString(), $automaticInstallment->statement->closing_date->toDateString());
        self::assertSame($manualInstallment->statement->due_date->toDateString(), $automaticInstallment->statement->due_date->toDateString());
        self::assertSame($manualInstallment->statement->status, $automaticInstallment->statement->status);
        self::assertSame($manualInstallment->recognition_date->toDateString(), $automaticInstallment->recognition_date->toDateString());
        self::assertSame($manualInstallment->amount_centavos, $automaticInstallment->amount_centavos);
        self::assertSame($manualResponse->json('data.installments.0.statement.closing_date'), $automaticInstallment->statement->closing_date->toDateString());

        $recurringSummary = $this->getJson('/api/v1/credit-cards/'.$recurringCard->id)->assertOk()->json('data.summary');
        $manualSummary = $this->getJson('/api/v1/credit-cards/'.$manualCard->id)->assertOk()->json('data.summary');
        self::assertSame($manualSummary['used_credit'], $recurringSummary['used_credit']);
        self::assertSame($manualSummary['available_credit'], $recurringSummary['available_credit']);
    }

    /** @return iterable<string, array{string, int, int, int}> */
    public static function billingBoundaryCases(): iterable
    {
        for ($case = 0; $case < 100; $case++) {
            $date = CarbonImmutable::parse('2025-01-01')->addDays($case * 5)->toDateString();
            yield "billing $case / $date" => [$date, 1 + ($case * 7) % 31, 1 + ($case * 11) % 28, 1_000 + $case];
        }
    }
}
