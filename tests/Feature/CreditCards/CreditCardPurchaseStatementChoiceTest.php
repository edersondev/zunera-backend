<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCardStatement;
use App\Models\User;
use App\Services\CreditCards\BillingCycleCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardPurchaseStatementChoiceTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function one_hundred_dates_require_choice_only_after_their_inclusive_closing_day(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 25, 'due_day' => 28, 'credit_limit_centavos' => 1_000_000]);
        $category = $this->expenseCategory($user);
        $cycles = app(BillingCycleCalculator::class);

        for ($index = 0; $index < 100; $index++) {
            $date = CarbonImmutable::parse('2026-01-01')->addMonthsNoOverflow($index % 24)->addDays($index % 28)->toDateString();
            $closing = $cycles->cycleForDate($date, 25, 28)->closingDate;
            $today = CarbonImmutable::parse($closing)->addDays(($index % 3) - 1)->toDateString();
            $this->cardSignIn($today, $user);
            $this->getJson("/api/v1/credit-cards/{$card->id}/statement-options?purchase_date={$date}&limit=1")
                ->assertOk()
                ->assertJsonPath('data.date_derived_closing_date', $closing)
                ->assertJsonPath('data.requires_statement_choice', $today > $closing);
            $payload = [
                'category_id' => $category->id,
                'description' => 'Matrix purchase '.$index,
                'purchase_date' => $date,
                'total_amount_centavos' => 1_000,
                'installment_count' => 1,
            ];
            if ($today > $closing) {
                $payload['statement_closing_date'] = $closing;
            }
            $this->postJson("/api/v1/credit-cards/{$card->id}/purchases", $payload, ['Idempotency-Key' => 'choice-matrix-'.$index])
                ->assertCreated()->assertJsonPath('data.installments.0.statement.closing_date', $closing);
        }
    }

    #[Test]
    public function historical_period_and_new_configuration_are_both_selectable_without_writes(): void
    {
        $user = $this->cardSignIn('2026-02-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $statement = $this->statementFor($card, app(BillingCycleCalculator::class)->cycleForDate('2026-01-05', 10, 17));
        $card->update(['closing_day' => 25, 'due_day' => 28]);
        $before = CreditCardStatement::count();

        $this->getJson("/api/v1/credit-cards/{$card->id}/statement-options?purchase_date=2026-01-15&from_month=2026-01&limit=1")
            ->assertOk()
            ->assertJsonPath('data.date_derived_closing_date', '2026-01-25')
            ->assertJsonPath('data.requires_statement_choice', true)
            ->assertJsonCount(1, 'data.options')
            ->assertJsonPath('data.options.0.closing_date', '2026-01-25')
            ->assertJsonPath('data.options.0.is_persisted', false);

        $this->getJson("/api/v1/credit-cards/{$card->id}/statement-options?purchase_date=2026-01-05&from_month=2026-01&limit=1")
            ->assertOk()
            ->assertJsonPath('data.date_derived_closing_date', '2026-01-10')
            ->assertJsonPath('data.options.0.statement_id', $statement->id);

        $this->getJson("/api/v1/credit-cards/{$card->id}/statement-options?purchase_date=2026-01-15&from_month=2028-02&limit=1")
            ->assertOk()
            ->assertJsonPath('data.options.0.closing_date', '2028-02-25');
        self::assertSame($before, CreditCardStatement::count());
    }

    #[Test]
    public function manual_closed_cycle_requires_valid_choice_and_preserves_chosen_first_cycle(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $payload = [
            'category_id' => $this->expenseCategory($user)->id,
            'description' => 'Backdated purchase',
            'purchase_date' => '2026-08-05',
            'total_amount_centavos' => 9_001,
            'installment_count' => 2,
        ];

        $this->postJson("/api/v1/credit-cards/{$card->id}/purchases", $payload, ['Idempotency-Key' => 'choice-missing'])
            ->assertUnprocessable()->assertJsonValidationErrors('statement_closing_date');
        $this->postJson("/api/v1/credit-cards/{$card->id}/purchases", $payload + ['statement_closing_date' => '2026-08-11'], ['Idempotency-Key' => 'choice-invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('statement_closing_date');

        $response = $this->postJson("/api/v1/credit-cards/{$card->id}/purchases", $payload + ['statement_closing_date' => '2026-10-10'], ['Idempotency-Key' => 'choice-valid'])
            ->assertCreated()
            ->assertJsonPath('data.installments.0.statement.closing_date', '2026-10-10')
            ->assertJsonPath('data.installments.1.statement.closing_date', '2026-11-10');
        $this->postJson("/api/v1/credit-cards/{$card->id}/purchases", $payload + ['statement_closing_date' => '2026-10-10'], ['Idempotency-Key' => 'choice-valid'])
            ->assertCreated()->assertExactJson($response->json());

        $this->postJson("/api/v1/credit-cards/{$card->id}/purchases", [
            ...$payload, 'purchase_date' => '2026-09-15', 'statement_closing_date' => '2026-11-10',
        ], ['Idempotency-Key' => 'choice-open'])->assertUnprocessable()->assertJsonValidationErrors('statement_closing_date');
    }

    #[Test]
    public function options_validate_dates_window_and_owner_scope(): void
    {
        $this->cardSignIn('2026-09-20');
        $foreign = $this->activeCard(User::factory()->create());
        $this->getJson("/api/v1/credit-cards/{$foreign->id}/statement-options?purchase_date=2026-09-05")->assertNotFound();
        $card = $this->activeCard(auth()->user(), ['name' => 'Owned options card']);
        foreach (['2026-02-30', '1899-12-31', '2101-01-01'] as $date) {
            $this->getJson("/api/v1/credit-cards/{$card->id}/statement-options?purchase_date={$date}")
                ->assertUnprocessable()->assertJsonValidationErrors('purchase_date');
        }
        $this->getJson("/api/v1/credit-cards/{$card->id}/statement-options?purchase_date=2026-09-05&from_month=10000-01")
            ->assertUnprocessable()->assertJsonValidationErrors('from_month');
        $this->getJson("/api/v1/credit-cards/{$card->id}/statement-options?purchase_date=2026-09-05&limit=25")
            ->assertUnprocessable()->assertJsonValidationErrors('limit');
    }

    #[Test]
    public function stale_choice_and_four_digit_schedule_overflow_fail_before_materialization(): void
    {
        $user = $this->cardSignIn('2026-09-20');
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 17]);
        $payload = [
            'category_id' => $this->expenseCategory($user)->id,
            'description' => 'Late cycle',
            'purchase_date' => '2026-08-05',
            'total_amount_centavos' => 2_000,
            'installment_count' => 2,
        ];
        $this->getJson("/api/v1/credit-cards/{$card->id}/statement-options?purchase_date=2026-08-05")
            ->assertOk()->assertJsonPath('data.date_derived_closing_date', '2026-08-10');
        $card->update(['closing_day' => 25, 'due_day' => 28]);
        $this->postJson("/api/v1/credit-cards/{$card->id}/purchases", $payload + ['statement_closing_date' => '2026-08-10'], ['Idempotency-Key' => 'stale-choice'])
            ->assertUnprocessable()->assertJsonValidationErrors('statement_closing_date');
        $this->postJson("/api/v1/credit-cards/{$card->id}/purchases", $payload + ['statement_closing_date' => '9999-12-25'], ['Idempotency-Key' => 'schedule-overflow'])
            ->assertUnprocessable()->assertJsonValidationErrors('statement_closing_date');

        $card->update(['closing_day' => 31, 'due_day' => 1]);
        $this->postJson("/api/v1/credit-cards/{$card->id}/purchases", [
            ...$payload, 'installment_count' => 1, 'statement_closing_date' => '9999-12-31',
        ], ['Idempotency-Key' => 'first-due-overflow'])
            ->assertUnprocessable()->assertJsonValidationErrors('statement_closing_date');
        self::assertSame(0, CreditCardStatement::count());
    }
}
