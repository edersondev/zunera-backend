<?php

declare(strict_types=1);

namespace App\Data\FinancialDashboard;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Inclusive reporting period shared by every dashboard projection.
 *
 * Presets resolve against the Brazilian business date; custom periods carry the
 * caller-supplied inclusive boundaries. The derived interval keeps chart scope
 * deterministic: daily through 31 days, weekly through 93, monthly beyond.
 */
final readonly class DashboardPeriodData
{
    public const string CURRENT_MONTH = 'current_month';

    public const string PREVIOUS_MONTH = 'previous_month';

    public const string CUSTOM = 'custom';

    public const string BUSINESS_TIMEZONE = 'America/Sao_Paulo';

    private function __construct(
        public string $preset,
        public string $from,
        public string $to,
    ) {}

    public static function businessDate(): string
    {
        return CarbonImmutable::now(self::BUSINESS_TIMEZONE)->toDateString();
    }

    public static function currentMonth(?string $businessDate = null): self
    {
        $business = $businessDate ?? self::businessDate();
        $cursor = CarbonImmutable::parse($business);

        return new self(self::CURRENT_MONTH, $cursor->startOfMonth()->toDateString(), $cursor->toDateString());
    }

    public static function previousMonth(?string $businessDate = null): self
    {
        $business = $businessDate ?? self::businessDate();
        $cursor = CarbonImmutable::parse($business)->subMonthNoOverflow()->startOfMonth();

        return new self(self::PREVIOUS_MONTH, $cursor->toDateString(), $cursor->endOfMonth()->toDateString());
    }

    public static function custom(string $from, string $to): self
    {
        if ($from > $to) {
            throw new InvalidArgumentException('Dashboard custom period start must be on or before its end.');
        }

        return new self(self::CUSTOM, $from, $to);
    }

    public static function fromPreset(string $preset, ?string $businessDate = null): self
    {
        return match ($preset) {
            self::CURRENT_MONTH => self::currentMonth($businessDate),
            self::PREVIOUS_MONTH => self::previousMonth($businessDate),
            default => throw new InvalidArgumentException('Unsupported dashboard period preset.'),
        };
    }

    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    public function interval(): string
    {
        return match (true) {
            $this->days() <= 31 => 'daily',
            $this->days() <= 93 => 'weekly',
            default => 'monthly',
        };
    }

    /** @return array{preset: string, from: string, to: string} */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
