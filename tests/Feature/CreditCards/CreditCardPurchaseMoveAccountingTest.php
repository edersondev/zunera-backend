<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCardCreditApplication;
use App\Models\CreditCardPurchaseMove;
use App\Models\NotificationProjectionFact;
use App\Services\CreditCards\CreditCardBudgetProjectionService;
use App\Services\CreditCards\RecognizedCardExpenseProjection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardPurchaseMoveAccountingTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function one_hundred_distinct_moves_keep_exact_amounts_and_single_statement_assignments(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $category = $this->expenseCategory($user);
        $checked = 0;

        for ($case = 0; $case < 100; $case++) {
            $closingDay = 5 + ($case % 20);
            fake()->unique(true);
            $card = $this->activeCard($user, ['name' => 'Matrix card '.$case, 'closing_day' => $closingDay, 'due_day' => 28]);
            $purchaseDate = CarbonImmutable::parse('2026-01-01')->addMonthsNoOverflow($case % 24)->startOfMonth()->addDay()->toDateString();
            $amount = 10_001 + $case;
            $purchase = $this->recordPurchase($card, $category, $amount, 2, $purchaseDate);
            $installments = $purchase->installments()->with('statement')->orderBy('sequence')->get();
            $firstClosing = $installments[0]->statement->closing_date->toDateString();
            $secondClosing = $installments[1]->statement->closing_date->toDateString();
            $mode = $case % 2 === 0 ? 'full_schedule' : 'one_installment';
            $target = $mode === 'full_schedule'
                ? CarbonImmutable::parse($secondClosing)->addMonthNoOverflow()->day(min($closingDay, CarbonImmutable::parse($secondClosing)->addMonthNoOverflow()->daysInMonth))->toDateString()
                : $secondClosing;
            $choice = ['mode' => $mode, 'target_closing_date' => $target];
            if ($mode === 'one_installment') {
                $choice['installment_id'] = $installments[0]->id;
            }

            $preview = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", $choice)->assertOk();
            $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", [
                ...$choice,
                'preview_token' => $preview->json('data.preview_token'),
            ], ['Idempotency-Key' => 'matrix-move-'.$case])->assertOk();

            $after = $purchase->installments()->with('statement')->orderBy('sequence')->get();
            self::assertCount(2, $after);
            self::assertSame($amount, $after->sum('amount_centavos'));
            self::assertSame($installments->pluck('id')->all(), $after->pluck('id')->all());
            self::assertNotSame($firstClosing, $after[0]->statement->closing_date->toDateString());
            self::assertSame($amount, $this->cardSummary($card)['used_credit_centavos']);
            $checked++;
        }

        self::assertSame(100, $checked);
    }

    #[Test]
    public function descriptive_correction_of_moved_purchase_keeps_installments_and_audit(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $category = $this->expenseCategory($user);
        $other = $this->expenseCategory($user, ['name' => 'Other expense']);
        $purchase = $this->recordPurchase($card, $category, 9_000, 2, '2026-09-15');
        $choice = ['mode' => 'full_schedule', 'target_closing_date' => '2026-12-10'];
        $preview = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", $choice)->assertOk();
        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", [
            ...$choice,
            'preview_token' => $preview->json('data.preview_token'),
        ], ['Idempotency-Key' => 'move-before-correction'])->assertOk();
        $before = $purchase->installments()->orderBy('sequence')->get()->map->only(['id', 'credit_card_statement_id', 'recognition_date'])->all();
        $history = CreditCardPurchaseMove::query()->where('credit_card_purchase_id', $purchase->id)->pluck('id')->all();

        $this->patchJson("/api/v1/credit-card-purchases/{$purchase->id}", [
            'description' => 'New description',
            'category_id' => $other->id,
            'notes' => 'New notes',
        ], ['Idempotency-Key' => 'descriptive-correction'])->assertOk();

        self::assertEquals($before, $purchase->installments()->orderBy('sequence')->get()->map->only(['id', 'credit_card_statement_id', 'recognition_date'])->all());
        self::assertSame($history, CreditCardPurchaseMove::query()->where('credit_card_purchase_id', $purchase->id)->pluck('id')->all());

        foreach (['purchase_date' => '2026-09-16', 'total_amount_centavos' => 8_000, 'installment_count' => 1] as $field => $value) {
            $this->patchJson("/api/v1/credit-card-purchases/{$purchase->id}", [$field => $value], ['Idempotency-Key' => 'allocation-correction-'.$field])
                ->assertStatus(409);
        }

        self::assertEquals($before, $purchase->installments()->orderBy('sequence')->get()->map->only(['id', 'credit_card_statement_id', 'recognition_date'])->all());
    }

    #[Test]
    public function linked_credit_follows_moved_installment_and_retains_audit_rows(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 20_000, 2, '2026-08-05');
        $first = $purchase->installments()->with('statement')->orderBy('sequence')->firstOrFail();
        $oldStatement = $first->statement;
        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/credit-events", [
            'reason' => 'refund', 'amount_centavos' => 5_000, 'event_date' => '2026-09-19',
        ], ['Idempotency-Key' => 'linked-credit-before-move'])->assertCreated();
        $application = CreditCardCreditApplication::query()->where('credit_card_installment_id', $first->id)->firstOrFail();

        $choice = ['mode' => 'one_installment', 'installment_id' => $first->id, 'target_closing_date' => '2026-09-10'];
        $preview = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", $choice)->assertOk();
        self::assertSame(0, collect($preview->json('data.affected_statements'))->firstWhere('closing_date', '2026-08-10')['outstanding_after_centavos']);
        self::assertSame(15_000, collect($preview->json('data.affected_statements'))->firstWhere('closing_date', '2026-09-10')['outstanding_after_centavos']);
        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", [
            ...$choice, 'preview_token' => $preview->json('data.preview_token'),
        ], ['Idempotency-Key' => 'linked-credit-move'])->assertOk();

        $first->refresh();
        self::assertNotSame($oldStatement->id, $first->credit_card_statement_id);
        self::assertNotNull($application->refresh()->superseded_at);
        self::assertSame(5_000, CreditCardCreditApplication::query()->active()->where('credit_card_installment_id', $first->id)->sum('amount_centavos'));
        self::assertSame($first->credit_card_statement_id, CreditCardCreditApplication::query()->active()->where('credit_card_installment_id', $first->id)->firstOrFail()->credit_card_statement_id);
        self::assertSame(0, $oldStatement->refresh()->credit_adjustment_centavos);
        self::assertSame(5_000, $first->statement->refresh()->credit_adjustment_centavos);
        self::assertSame(15_000, $this->cardSummary($card)['used_credit_centavos']);
    }

    #[Test]
    public function moving_from_paid_statement_reuses_surplus_without_moving_cash(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 50_000, 'current_balance_centavos' => 50_000]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 20_000, 1, '2026-08-05');
        $source = $purchase->installments()->firstOrFail()->statement;
        $payment = $this->payStatement($source, $account, 20_000, '2026-08-15');
        $choice = ['mode' => 'full_schedule', 'target_closing_date' => '2026-10-10'];
        $preview = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", $choice)->assertOk();
        self::assertSame(0, collect($preview->json('data.affected_statements'))->firstWhere('closing_date', '2026-10-10')['outstanding_after_centavos']);
        self::assertSame(20_000, collect($preview->json('data.affected_statements'))->firstWhere('closing_date', '2026-08-10')['payment_surplus_after_centavos']);
        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", [
            ...$choice, 'preview_token' => $preview->json('data.preview_token'),
        ], ['Idempotency-Key' => 'paid-source-move'])->assertOk();

        self::assertSame($source->id, $payment->refresh()->credit_card_statement_id);
        self::assertSame(30_000, $account->refresh()->current_balance_centavos);
        self::assertSame(0, $source->refresh()->original_amount_centavos);
        self::assertSame(20_000, $source->paid_centavos);
        self::assertSame(0, $this->cardSummary($card)['used_credit_centavos']);
        self::assertSame(0, $this->cardSummary($card)['card_credit_centavos']);
    }

    #[Test]
    public function move_restates_recognition_budget_dashboard_and_statement_notifications(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $category = $this->expenseCategory($user);
        $purchase = $this->recordPurchase($card, $category, 10_000, 1, '2026-08-05');
        $sourceId = $purchase->installments()->firstOrFail()->credit_card_statement_id;
        $choice = ['mode' => 'full_schedule', 'target_closing_date' => '2026-10-10'];
        $preview = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", $choice)->assertOk();
        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", [
            ...$choice, 'preview_token' => $preview->json('data.preview_token'),
        ], ['Idempotency-Key' => 'move-projections'])->assertOk();

        $recognition = app(RecognizedCardExpenseProjection::class);
        $budget = app(CreditCardBudgetProjectionService::class);
        self::assertSame(0, $recognition->installments((int) $user->id, '2026-08-01', '2026-08-31')->get()->sum('recognized_amount_centavos'));
        self::assertSame(10_000, (int) $recognition->installments((int) $user->id, '2026-10-01', '2026-10-31', false)->get()->sum('recognized_amount_centavos'));
        self::assertSame(0, (int) $budget->realizedByCategory($user, '2026-08-01', '2026-08-31')->get($category->id, 0));
        self::assertSame(10_000, (int) $budget->expectedByCategory($user, '2026-10-01', '2026-10-31')->get($category->id, 0));
        $this->getJson('/api/v1/financial-dashboard/credit-cards')->assertOk()
            ->assertJsonPath('data.outstanding_obligation.amount_centavos', 10_000);
        $destinationId = $purchase->installments()->firstOrFail()->credit_card_statement_id;
        self::assertSame(2, NotificationProjectionFact::query()
            ->where('source_kind', 'credit_card_statement')
            ->whereIn('source_id', [$sourceId, $destinationId])
            ->count());
    }

    #[Test]
    public function full_schedule_rebuilds_a_custom_gap_even_when_first_cycle_is_unchanged(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 9_000, 3, '2026-09-15');
        $last = $purchase->installments()->get()->last();
        $gap = ['mode' => 'one_installment', 'installment_id' => $last->id, 'target_closing_date' => '2027-01-10'];
        $preview = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", $gap)->assertOk();
        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", [
            ...$gap, 'preview_token' => $preview->json('data.preview_token'),
        ], ['Idempotency-Key' => 'make-gap'])->assertOk();

        $full = ['mode' => 'full_schedule', 'target_closing_date' => '2026-10-10'];
        $preview = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", $full)->assertOk()
            ->assertJsonCount(1, 'data.affected_installments');
        $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", [
            ...$full, 'preview_token' => $preview->json('data.preview_token'),
        ], ['Idempotency-Key' => 'rebuild-gap'])->assertOk()->assertJsonCount(1, 'data.moves');

        self::assertSame(['2026-10-10', '2026-11-10', '2026-12-10'], $purchase->installments()->with('statement')->orderBy('sequence')->get()
            ->map(fn ($installment) => $installment->statement->closing_date->toDateString())->all());
        self::assertSame(2, CreditCardPurchaseMove::query()->where('credit_card_purchase_id', $purchase->id)->count());
        self::assertSame(9_000, $this->cardSummary($card)['used_credit_centavos']);
    }
}
