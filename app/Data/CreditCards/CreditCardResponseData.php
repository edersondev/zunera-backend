<?php

declare(strict_types=1);

namespace App\Data\CreditCards;

use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Services\CreditCards\CreditCardObligationReconciler;
use Carbon\CarbonImmutable;

final class CreditCardResponseData
{
    /** @return array{amount_centavos: int, currency_code: string} */
    public static function money(int $centavos): array
    {
        return ['amount_centavos' => $centavos, 'currency_code' => 'BRL'];
    }

    /** @return array<string, mixed> */
    public static function identity(CreditCard $card): array
    {
        return [
            'id' => $card->id,
            'name' => $card->name,
            'institution_name' => $card->institution_name,
            'last_four' => $card->last_four,
            'color' => $card->color,
            'icon' => $card->icon,
            'status' => $card->status->value,
        ];
    }

    /** @return array<string, mixed> */
    public static function card(
        CreditCard $card,
        CreditCardObligationReconciler $reconciler,
        CarbonImmutable $businessDate,
    ): array {
        return [
            ...self::identity($card),
            'closing_day' => $card->closing_day,
            'due_day' => $card->due_day,
            'summary' => self::summary($card, $reconciler),
            'current_statement' => self::currentStatement($card, $reconciler, $businessDate),
        ];
    }

    /** @return array<string, mixed> */
    public static function summary(CreditCard $card, CreditCardObligationReconciler $reconciler): array
    {
        $summary = $reconciler->summary($card);

        return [
            'credit_limit' => self::money($summary['credit_limit_centavos']),
            'used_credit' => self::money($summary['used_credit_centavos']),
            'card_credit' => self::money($summary['card_credit_centavos']),
            'available_credit' => self::money($summary['available_credit_centavos']),
            'is_over_limit' => $summary['is_over_limit'],
        ];
    }

    /** @return array<string, mixed> */
    private static function currentStatement(
        CreditCard $card,
        CreditCardObligationReconciler $reconciler,
        CarbonImmutable $businessDate,
    ): array {
        if ($card->isActive()) {
            $statement = $reconciler->currentStatement($card, $businessDate);

            return $statement instanceof CreditCardStatement
                ? StatementResponseData::summary($statement, $card, true, $businessDate)
                : StatementResponseData::synthesizedCurrent($card, $businessDate, true);
        }

        $latest = CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->orderByDesc('closing_date')
            ->first();

        return $latest instanceof CreditCardStatement
            ? StatementResponseData::summary($latest, $card, false, $businessDate)
            : StatementResponseData::synthesizedCurrent($card, $businessDate, false);
    }
}
