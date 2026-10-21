<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Data\CreditCards\BillingCycleData;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Resolves persisted billing snapshots and selectable empty cycles by closing date. */
final class StatementCycleResolver
{
    public function __construct(private readonly BillingCycleCalculator $calculator) {}

    public function resolveForDate(CreditCard $card, string $purchaseDate): BillingCycleData
    {
        $statement = CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->whereDate('period_from', '<=', $purchaseDate)
            ->whereDate('period_to', '>=', $purchaseDate)
            ->orderBy('closing_date')
            ->orderBy('id')
            ->first();

        if ($statement instanceof CreditCardStatement) {
            return $this->fromStatement($statement);
        }

        $calculated = $this->calculator->cycleForDate($purchaseDate, $card->closing_day, $card->due_day);
        $this->assertRepresentable($calculated, 'purchase_date');

        return $this->storedOrCalculated($card, $calculated);
    }

    public function resolveChoice(CreditCard $card, string $purchaseDate, string $closingDate, string $field = 'target_closing_date'): BillingCycleData
    {
        $this->parseDate($closingDate, $field);
        if ($closingDate < $purchaseDate) {
            throw ValidationException::withMessages([$field => ['Statement must close on or after the purchase date.']]);
        }

        $stored = CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->whereDate('closing_date', $closingDate)
            ->first();
        if ($stored instanceof CreditCardStatement) {
            $cycle = $this->fromStatement($stored);
            $this->assertRepresentable($cycle, $field);

            return $cycle;
        }

        $closing = $this->parseDate($closingDate, $field);
        $canonical = $this->calculator->cycleForDate($closing->startOfMonth(), $card->closing_day, $card->due_day);
        if ($canonical->closingDate !== $closingDate) {
            throw ValidationException::withMessages([$field => ['Statement closing date is not an eligible billing cycle.']]);
        }

        $this->assertRepresentable($canonical, $field);

        return $canonical;
    }

    /** @return list<BillingCycleData> */
    public function scheduleFrom(CreditCard $card, string $purchaseDate, string $closingDate, int $count, string $field = 'target_closing_date'): array
    {
        $first = $this->resolveChoice($card, $purchaseDate, $closingDate, $field);
        $schedule = [$first];
        for ($index = 1; $index < $count; $index++) {
            $next = $this->calculator->nextCycle($schedule[$index - 1], $card->closing_day, $card->due_day);
            $this->assertRepresentable($next, $field);
            $schedule[] = $this->storedOrCalculated($card, $next);
        }

        return $schedule;
    }

    /** @return array{business_date: string, date_derived_closing_date: string, requires_statement_choice: bool, options: list<array<string, mixed>>, next_from_month: ?string} */
    public function options(CreditCard $card, string $purchaseDate, ?string $fromMonth = null, int $limit = 12): array
    {
        $purchaseDate = CreditCardPurchaseDateRange::normalize($purchaseDate);
        $derived = $this->resolveForDate($card, $purchaseDate);
        $businessDate = $this->calculator->businessToday()->toDateString();
        $month = $fromMonth ?? substr($derived->closingDate, 0, 7);
        if (preg_match('/^(?!0000)\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1 || $limit < 1 || $limit > 24) {
            throw ValidationException::withMessages(['from_month' => ['Choose a valid statement month and limit.']]);
        }

        $cursor = $this->parseDate($month.'-01', 'from_month');
        $choices = [];
        for ($index = 0; $index < $limit; $index++) {
            $yearMonth = $cursor->format('Y-m');
            $stored = CreditCardStatement::query()
                ->where('credit_card_id', $card->id)
                ->whereYear('closing_date', $cursor->year)
                ->whereMonth('closing_date', $cursor->month)
                ->orderBy('closing_date')
                ->get();
            foreach ($stored as $statement) {
                $cycle = $this->fromStatement($statement);
                if ($cycle->closingDate >= $purchaseDate) {
                    $choices[$cycle->closingDate] = $this->option($cycle, $statement->id, $businessDate);
                }
            }

            $calculated = $this->calculator->cycleForDate($cursor, $card->closing_day, $card->due_day);
            if (str_starts_with($calculated->closingDate, $yearMonth) && $calculated->closingDate >= $purchaseDate) {
                try {
                    $this->assertRepresentable($calculated, 'from_month');
                    $choice = $this->storedOrCalculated($card, $calculated);
                    $persisted = $stored->first(fn (CreditCardStatement $row) => $row->closing_date->toDateString() === $choice->closingDate);
                    $choices[$choice->closingDate] = $this->option($choice, $persisted?->id, $businessDate);
                } catch (ValidationException) {
                    // The last representable month can have an unrepresentable due date.
                }
            }

            $cursor = $cursor->addMonthNoOverflow();
            if ($cursor->year > 9999) {
                break;
            }
        }

        ksort($choices);
        $nextMonth = $cursor->year <= 9999 ? $cursor->format('Y-m') : null;

        return [
            'business_date' => $businessDate,
            'date_derived_closing_date' => $derived->closingDate,
            'requires_statement_choice' => $derived->closingDate < $businessDate,
            'options' => array_values($choices),
            'next_from_month' => $nextMonth,
        ];
    }

    private function storedOrCalculated(CreditCard $card, BillingCycleData $cycle): BillingCycleData
    {
        $statement = CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->whereDate('closing_date', $cycle->closingDate)
            ->first();

        return $statement instanceof CreditCardStatement ? $this->fromStatement($statement) : $cycle;
    }

    private function fromStatement(CreditCardStatement $statement): BillingCycleData
    {
        return new BillingCycleData(
            $statement->period_from->toDateString(),
            $statement->period_to->toDateString(),
            $statement->closing_date->toDateString(),
            $statement->due_date->toDateString(),
        );
    }

    /** @return array{closing_date: string, period_from: string, period_to: string, due_date: string, statement_id: ?int, closed_by_date: bool, is_persisted: bool} */
    private function option(BillingCycleData $cycle, ?int $statementId, string $businessDate): array
    {
        return [
            'closing_date' => $cycle->closingDate,
            'period_from' => $cycle->periodFrom,
            'period_to' => $cycle->periodTo,
            'due_date' => $cycle->dueDate,
            'statement_id' => $statementId,
            'closed_by_date' => $cycle->closingDate < $businessDate,
            'is_persisted' => $statementId !== null,
        ];
    }

    private function assertRepresentable(BillingCycleData $cycle, string $field): void
    {
        foreach ([$cycle->periodFrom, $cycle->periodTo, $cycle->closingDate, $cycle->dueDate] as $date) {
            $this->parseDate($date, $field);
        }
    }

    private function parseDate(string $date, string $field): CarbonImmutable
    {
        if (preg_match('/^(?!0000)\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw ValidationException::withMessages([$field => ['Date must fit a four-digit ISO calendar date.']]);
        }
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, CreditCardMoney::BUSINESS_TIME_ZONE);
        if ($parsed === false || $parsed->toDateString() !== $date) {
            throw ValidationException::withMessages([$field => ['Choose a valid calendar date.']]);
        }

        return $parsed;
    }
}
