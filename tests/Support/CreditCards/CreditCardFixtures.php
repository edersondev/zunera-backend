<?php

declare(strict_types=1);

namespace Tests\Support\CreditCards;

use App\Data\CreditCards\BillingCycleData;
use App\Enums\Categories\CategoryClassification;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardCreditEvent;
use App\Models\CreditCardInstallment;
use App\Models\CreditCardPurchase;
use App\Models\CreditCardStatement;
use App\Models\CreditCardStatementPayment;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\CreditCards\BillingCycleCalculator;
use App\Services\CreditCards\CreditCardObligationReconciler;
use App\Services\CreditCards\CreditCardPaymentAccountReconciler;
use App\Services\CreditCards\InstallmentAllocator;
use Carbon\CarbonImmutable;

/**
 * Shared builders for the Credit Cards suites. Installments are always produced
 * by the production allocator and cycle calculator so ownership, centavo, and
 * statement-assignment assertions exercise real domain rules.
 */
trait CreditCardFixtures
{
    protected const string CARD_BUSINESS_DATE = '2026-09-20';

    protected function cardBusinessDate(): string
    {
        return self::CARD_BUSINESS_DATE;
    }

    protected function freezeCardClock(?string $date = null): CarbonImmutable
    {
        $businessDate = CarbonImmutable::parse(($date ?? $this->cardBusinessDate()).' 10:00:00', 'America/Sao_Paulo');
        $this->travelTo($businessDate);

        return $businessDate;
    }

    protected function cardSignIn(?string $date = null, ?User $user = null): User
    {
        $this->freezeCardClock($date);

        // Signing in again after a time jump must not trip the guest-only login
        // route, so any existing session is dropped first.
        $this->deleteJson('/api/v1/auth/session');

        $user ??= User::factory()->create();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    protected function activeCard(User $user, array $attributes = []): CreditCard
    {
        return CreditCard::factory()->withUser($user)->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    protected function archivedCard(User $user, array $attributes = []): CreditCard
    {
        return CreditCard::factory()->withUser($user)->archived()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    protected function expenseCategory(User $user, array $attributes = []): Category
    {
        return Category::factory()->create(array_merge([
            'user_id' => $user->id,
            'classification' => CategoryClassification::Expense,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    protected function cardAccount(User $user, array $attributes = []): FinancialAccount
    {
        return FinancialAccount::factory()->create(array_merge(['user_id' => $user->id], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    protected function statementFor(CreditCard $card, BillingCycleData $cycle, array $attributes = []): CreditCardStatement
    {
        $existing = CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->whereDate('closing_date', $cycle->closingDate)
            ->first();

        if ($existing instanceof CreditCardStatement) {
            return $existing;
        }

        return CreditCardStatement::query()->create([
            'user_id' => $card->user_id,
            'credit_card_id' => $card->id,
            'period_from' => $cycle->periodFrom,
            'period_to' => $cycle->periodTo,
            'closing_date' => $cycle->closingDate,
            'due_date' => $cycle->dueDate,
            'status' => 'open',
        ] + $attributes);
    }

    /** Records a financially effective settlement and re-derives statement totals. */
    protected function payStatement(
        CreditCardStatement $statement,
        FinancialAccount $account,
        int $amountCentavos,
        ?string $paymentDate = null,
    ): CreditCardStatementPayment {
        $payment = CreditCardStatementPayment::factory()->create([
            'user_id' => $statement->user_id,
            'credit_card_statement_id' => $statement->id,
            'credit_card_id' => $statement->credit_card_id,
            'financial_account_id' => $account->id,
            'amount_centavos' => $amountCentavos,
            'payment_date' => $paymentDate ?? $statement->due_date->toDateString(),
        ]);

        // Fixture settlements must move the paying account exactly like the
        // production service so balance assertions stay meaningful.
        app(CreditCardPaymentAccountReconciler::class)->reconcile(null, $payment);
        $this->syncCard($statement->creditCard);

        return $payment;
    }

    /**
     * Records a purchase through the real allocation rules: exact centavo
     * installments, each assigned to its own consecutive billing statement.
     */
    protected function recordPurchase(
        CreditCard $card,
        Category $category,
        int $totalCentavos,
        int $installmentCount,
        string $purchaseDate,
        ?string $description = null,
    ): CreditCardPurchase {
        $allocator = app(InstallmentAllocator::class);
        $cycles = app(BillingCycleCalculator::class);

        $purchase = CreditCardPurchase::factory()
            ->forCard($card)
            ->withCategory($category)
            ->create([
                'description' => $description ?? 'Compra teste',
                'total_amount_centavos' => $totalCentavos,
                'installment_count' => $installmentCount,
                'purchase_date' => $purchaseDate,
            ]);

        $cycle = $cycles->cycleForDate($purchaseDate, $card->closing_day, $card->due_day);
        foreach ($allocator->allocate($totalCentavos, $installmentCount) as $index => $amountCentavos) {
            $statement = $this->statementFor($card, $cycle);
            CreditCardInstallment::factory()->forPurchase($purchase, $statement, $index + 1, $amountCentavos)->create();
            $cycle = $cycles->nextCycle($cycle, $card->closing_day, $card->due_day);
        }

        $this->syncCard($card);

        return $purchase->refresh();
    }

    /** @param array<string, mixed> $attributes */
    protected function recordCreditEvent(CreditCardPurchase $purchase, int $amountCentavos, array $attributes = []): CreditCardCreditEvent
    {
        return CreditCardCreditEvent::factory()->forPurchase($purchase, $amountCentavos)->create($attributes);
    }

    protected function syncCard(CreditCard $card, ?string $businessDate = null): void
    {
        $date = $businessDate === null
            ? CarbonImmutable::now('America/Sao_Paulo')->startOfDay()
            : CarbonImmutable::parse($businessDate, 'America/Sao_Paulo')->startOfDay();

        app(CreditCardObligationReconciler::class)->refreshCardStatements($card, $date);
    }

    protected function cardSummary(CreditCard $card): array
    {
        return app(CreditCardObligationReconciler::class)->summary($card);
    }
}
