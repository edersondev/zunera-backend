<?php

declare(strict_types=1);

namespace App\Data\FinancialReports;

use Carbon\CarbonImmutable;

/** One immutable owner, period, comparison, and filter context for a report read. */
final readonly class ReportScope
{
    public function __construct(
        public int $userId,
        public string $preset,
        public ?string $month,
        public string $currentFrom,
        public string $currentTo,
        public string $previousFrom,
        public string $previousTo,
        public ?int $accountId = null,
        public ?int $categoryId = null,
        public ?string $transactionType = null,
    ) {}

    public function currentDayCount(): int
    {
        return $this->days($this->currentFrom, $this->currentTo);
    }

    public function previousDayCount(): int
    {
        return $this->days($this->previousFrom, $this->previousTo);
    }

    public function isFiltered(): bool
    {
        return $this->accountId !== null || $this->categoryId !== null || $this->transactionType !== null;
    }

    /** @return array{from: string, to: string, day_count: int} */
    public function period(string $which): array
    {
        $from = $which === 'previous' ? $this->previousFrom : $this->currentFrom;
        $to = $which === 'previous' ? $this->previousTo : $this->currentTo;

        return ['from' => $from, 'to' => $to, 'day_count' => $this->days($from, $to)];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'month' => $this->month,
            'current_period' => $this->period('current'),
            'previous_period' => $this->period('previous'),
            'filters' => [
                'account_id' => $this->accountId,
                'category_id' => $this->categoryId,
                'transaction_type' => $this->transactionType,
            ],
            'is_filtered' => $this->isFiltered(),
        ];
    }

    private function days(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
    }
}
