<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCardPurchaseMove;
use App\Models\CreditCardStatementPayment;
use App\Models\NotificationProjectionFact;
use App\Models\Transaction;
use App\Services\CreditCards\CreditCardBudgetProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardPurchaseDeleteAccountingTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function one_hundred_moved_purchases_delete_without_reversing_statement_cash(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $category = $this->expenseCategory($user);
        $account = $this->cardAccount($user, ['initial_balance_centavos' => 1_000_000, 'current_balance_centavos' => 1_000_000]);
        $totalPaid = 0;

        for ($case = 0; $case < 100; $case++) {
            fake()->unique(true);
            $card = $this->activeCard($user, ['name' => 'Delete matrix '.$case, 'closing_day' => 10, 'due_day' => 17]);
            $purchaseDate = $case % 4 === 0 ? '2026-09-15' : '2026-08-05';
            $purchase = $this->recordPurchase($card, $category, 5_000 + $case, 1, $purchaseDate);
            $source = $purchase->installments()->firstOrFail()->statement;
            $paid = match ($case % 4) {
                2 => intdiv(5_000 + $case, 2),
                3 => 5_000 + $case,
                default => 0,
            };
            if ($paid > 0) {
                $this->payStatement($source, $account, $paid, '2026-08-17');
                $totalPaid += $paid;
            }
            $choice = ['mode' => 'full_schedule', 'target_closing_date' => $case % 4 === 0 ? '2026-11-10' : '2026-10-10'];
            $preview = $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move-preview", $choice)->assertOk();
            $this->postJson("/api/v1/credit-card-purchases/{$purchase->id}/move", [
                ...$choice, 'preview_token' => $preview->json('data.preview_token'),
            ], ['Idempotency-Key' => 'delete-matrix-move-'.$case])->assertOk();
            $this->deleteJson("/api/v1/credit-card-purchases/{$purchase->id}", [], ['Idempotency-Key' => 'delete-matrix-delete-'.$case])
                ->assertOk();

            self::assertSame(0, $this->cardSummary($card)['used_credit_centavos']);
            self::assertSame($paid, $this->cardSummary($card)['card_credit_centavos']);
            self::assertSame(0, $purchase->installments()->count());
            self::assertSame(0, CreditCardPurchaseMove::query()->where('credit_card_purchase_id', $purchase->id)->count());
            self::assertSame(1_000_000 - $totalPaid, $account->refresh()->current_balance_centavos);
        }

        self::assertSame(50, CreditCardStatementPayment::query()->where('financial_account_id', $account->id)->count());
        self::assertSame(0, Transaction::query()->where('financial_account_id', $account->id)->count());
    }

    #[Test]
    public function one_hundred_overlapping_delete_retries_have_one_effect(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user);
        $purchase = $this->recordPurchase($card, $this->expenseCategory($user), 9_000, 1, '2026-09-15');
        $uri = "/api/v1/credit-card-purchases/{$purchase->id}";
        $choice = ['mode' => 'full_schedule', 'target_closing_date' => '2026-11-10'];
        $preview = $this->postJson($uri.'/move-preview', $choice)->assertOk();
        $moveBody = [...$choice, 'preview_token' => $preview->json('data.preview_token')];
        $this->postJson($uri.'/move', $moveBody, ['Idempotency-Key' => 'predelete-move'])->assertOk();
        $first = $this->deleteJson($uri, [], ['Idempotency-Key' => 'same-delete'])->assertOk();

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $this->deleteJson($uri, [], ['Idempotency-Key' => 'same-delete'])->assertOk()->assertExactJson($first->json());
            $this->deleteJson($uri, [], ['Idempotency-Key' => 'different-delete-'.$attempt])->assertNotFound();
            $this->postJson($uri.'/move', $moveBody, ['Idempotency-Key' => 'predelete-move'])->assertStatus(409)->assertJsonPath('code', 'PURCHASE_DELETED');
            $this->postJson($uri.'/move', $moveBody, ['Idempotency-Key' => 'new-move-'.$attempt])->assertNotFound();
        }

        self::assertSame(0, $this->cardSummary($card)['used_credit_centavos']);
        self::assertSame(0, Transaction::count());
    }

    #[Test]
    public function delete_clears_budget_dashboard_and_history_with_one_statement_notification(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user);
        $category = $this->expenseCategory($user);
        $purchase = $this->recordPurchase($card, $category, 7_000, 1, '2026-09-15');
        $statementId = $purchase->installments()->firstOrFail()->credit_card_statement_id;
        $this->deleteJson("/api/v1/credit-card-purchases/{$purchase->id}", [], ['Idempotency-Key' => 'delete-projections'])->assertOk();

        self::assertSame(0, (int) app(CreditCardBudgetProjectionService::class)
            ->expectedByCategory($user, '2026-10-01', '2026-10-31')->get($category->id, 0));
        $this->getJson('/api/v1/financial-dashboard/credit-cards')->assertOk()
            ->assertJsonPath('data.outstanding_obligation.amount_centavos', 0);
        $this->getJson("/api/v1/credit-cards/{$card->id}/purchases")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/credit-card-purchases/{$purchase->id}")->assertNotFound();
        self::assertSame(1, NotificationProjectionFact::query()
            ->where('source_kind', 'credit_card_statement')->where('source_id', $statementId)->count());
    }
}
