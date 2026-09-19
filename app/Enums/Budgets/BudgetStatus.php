<?php

declare(strict_types=1);

namespace App\Enums\Budgets;

/**
 * Server-owned budget status thresholds. Comparison happens in exact centavos
 * so a rounded percentage can never move a plan into the wrong state.
 */
enum BudgetStatus: string
{
    case Within = 'within';
    case Approaching = 'approaching';
    case Reached = 'reached';
    case Exceeded = 'exceeded';
    case NotApplicable = 'not_applicable';

    public static function fromAmounts(int $spentCentavos, int $plannedCentavos): self
    {
        if ($plannedCentavos <= 0) {
            return self::NotApplicable;
        }

        if ($spentCentavos * 100 < $plannedCentavos * 80) {
            return self::Within;
        }

        if ($spentCentavos < $plannedCentavos) {
            return self::Approaching;
        }

        return $spentCentavos === $plannedCentavos ? self::Reached : self::Exceeded;
    }
}
