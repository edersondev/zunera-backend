<?php

declare(strict_types=1);

namespace Tests\Unit\CreditCards;

use App\Data\CreditCards\BillingCycleData;
use App\Models\CreditCardStatement;
use App\Models\User;
use App\Services\CreditCards\StatementCycleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class StatementCycleResolverTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    #[Test]
    public function stored_period_containing_purchase_date_wins_over_current_billing_days(): void
    {
        $user = User::factory()->create();
        $card = $this->activeCard($user, ['closing_day' => 25, 'due_day' => 5]);
        $this->statementFor($card, new BillingCycleData('2025-12-11', '2026-01-10', '2026-01-10', '2026-01-20'));

        $cycle = app(StatementCycleResolver::class)->resolveForDate($card, '2026-01-05');

        self::assertSame('2026-01-10', $cycle->closingDate);
        self::assertSame('2025-12-11', $cycle->periodFrom);
    }

    #[Test]
    public function old_statement_in_same_month_does_not_hide_new_calculated_cycle(): void
    {
        $user = User::factory()->create();
        $card = $this->activeCard($user, ['closing_day' => 25, 'due_day' => 5]);
        $this->statementFor($card, new BillingCycleData('2025-12-11', '2026-01-10', '2026-01-10', '2026-01-20'));

        $cycle = app(StatementCycleResolver::class)->resolveForDate($card, '2026-01-15');

        self::assertSame('2026-01-25', $cycle->closingDate);
        self::assertSame('2025-12-26', $cycle->periodFrom);
        self::assertSame('2026-02-05', $cycle->dueDate);
    }

    #[Test]
    public function earliest_closing_date_wins_when_stored_periods_overlap(): void
    {
        $user = User::factory()->create();
        $card = $this->activeCard($user, ['closing_day' => 25, 'due_day' => 5]);
        $this->statementFor($card, new BillingCycleData('2026-01-01', '2026-01-20', '2026-01-20', '2026-02-05'));
        $this->statementFor($card, new BillingCycleData('2026-01-11', '2026-01-25', '2026-01-25', '2026-02-05'));

        $cycle = app(StatementCycleResolver::class)->resolveForDate($card, '2026-01-15');

        self::assertSame('2026-01-20', $cycle->closingDate);
    }

    #[Test]
    public function exact_closing_date_reuses_historical_due_and_period_snapshots(): void
    {
        $user = User::factory()->create();
        $card = $this->activeCard($user, ['closing_day' => 25, 'due_day' => 5]);
        $this->statementFor($card, new BillingCycleData('2026-02-01', '2026-02-28', '2026-02-28', '2026-03-12'));

        $cycle = app(StatementCycleResolver::class)->resolveChoice($card, '2026-01-15', '2026-02-28');

        self::assertSame('2026-02-01', $cycle->periodFrom);
        self::assertSame('2026-03-12', $cycle->dueDate);
    }

    #[Test]
    public function direct_future_month_lookup_is_windowed_and_does_not_create_a_statement(): void
    {
        $this->freezeCardClock('2026-02-01');
        $user = User::factory()->create();
        $card = $this->activeCard($user, ['closing_day' => 31, 'due_day' => 5]);

        $result = app(StatementCycleResolver::class)->options($card, '2026-01-15', '2028-02', 1);

        self::assertSame('2028-02-29', $result['options'][0]['closing_date']);
        self::assertSame('2028-03-05', $result['options'][0]['due_date']);
        self::assertFalse($result['options'][0]['is_persisted']);
        self::assertSame('2028-03', $result['next_from_month']);
        self::assertSame(0, CreditCardStatement::query()->where('credit_card_id', $card->id)->count());
    }

    #[Test]
    public function closing_date_is_inclusive_when_deciding_if_statement_choice_is_required(): void
    {
        $this->freezeCardClock('2026-03-10');
        $user = User::factory()->create();
        $card = $this->activeCard($user, ['closing_day' => 10, 'due_day' => 20]);

        $onClosing = app(StatementCycleResolver::class)->options($card, '2026-03-10');
        self::assertFalse($onClosing['requires_statement_choice']);

        $this->freezeCardClock('2026-03-11');
        $afterClosing = app(StatementCycleResolver::class)->options($card, '2026-03-10');
        self::assertTrue($afterClosing['requires_statement_choice']);
    }
}
