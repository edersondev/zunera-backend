<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Data\CreditCards\CreditCardResponseData;
use App\Data\CreditCards\StatementResponseData;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Additive dashboard read data. Card obligations never mix with cash balances,
 * and the projection is derived at read time from the same reconciler the card
 * screens use.
 */
final class CreditCardDashboardProjectionService
{
    public const int UPCOMING_LIMIT = 10;

    public function __construct(
        private readonly CreditCardObligationReconciler $reconciler,
        private readonly BillingCycleCalculator $cycles,
    ) {}

    /** @return array<string, mixed> */
    public function summary(User $user, ?string $businessDate = null): array
    {
        $business = $businessDate === null
            ? $this->cycles->businessToday()
            : CarbonImmutable::parse($businessDate, CreditCardMoney::BUSINESS_TIME_ZONE)->startOfDay();

        $cards = CreditCard::query()
            ->where('user_id', $user->id)
            ->active()
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $outstanding = 0;
        $cardCredit = 0;
        $available = 0;
        $cardPayloads = [];

        foreach ($cards as $card) {
            $summary = $this->reconciler->summary($card);
            $outstanding += $summary['used_credit_centavos'];
            $cardCredit += $summary['card_credit_centavos'];
            $available += $summary['available_credit_centavos'];
            $cardPayloads[] = CreditCardResponseData::card($card, $this->reconciler, $business);
        }

        $upcoming = CreditCardStatement::query()
            ->with('creditCard')
            ->where('user_id', $user->id)
            ->whereHas('creditCard', fn ($query) => $query->active())
            ->whereRaw('(original_amount_centavos - credit_adjustment_centavos - paid_centavos - card_credit_applied_centavos) > 0')
            ->whereDate('due_date', '>=', $business->toDateString())
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::UPCOMING_LIMIT)
            ->get()
            ->map(fn (CreditCardStatement $statement) => StatementResponseData::summary(
                $statement,
                $statement->creditCard,
                false,
                $business,
            ))
            ->all();

        return [
            'outstanding_obligation' => CreditCardResponseData::money($outstanding),
            'card_credit' => CreditCardResponseData::money($cardCredit),
            'available_credit' => CreditCardResponseData::money($available),
            'cards' => $cardPayloads,
            'upcoming_statements' => $upcoming,
        ];
    }
}
