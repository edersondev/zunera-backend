<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use InvalidArgumentException;

/**
 * Splits a purchase total into consecutive centavo-exact installments using the
 * earliest-installment remainder rule: every installment receives the whole
 * division and the indivisible centavos are handed out one at a time starting
 * with the first installment. The sum always equals the purchase total.
 */
final class InstallmentAllocator
{
    /** @return list<int> */
    public function allocate(int $totalCentavos, int $installmentCount): array
    {
        if ($installmentCount < CreditCardMoney::MIN_INSTALLMENT_COUNT || $installmentCount > CreditCardMoney::MAX_INSTALLMENT_COUNT) {
            throw new InvalidArgumentException('Installment count is outside the supported range.');
        }

        if ($totalCentavos < CreditCardMoney::MIN_AMOUNT_CENTAVOS) {
            throw new InvalidArgumentException('Purchase total must be a positive centavo amount.');
        }

        if ($totalCentavos < $installmentCount) {
            throw new InvalidArgumentException('Each installment must be at least one centavo.');
        }

        $base = intdiv($totalCentavos, $installmentCount);
        $remainder = $totalCentavos % $installmentCount;

        $installments = [];
        for ($sequence = 1; $sequence <= $installmentCount; $sequence++) {
            $installments[] = $sequence <= $remainder ? $base + 1 : $base;
        }

        return $installments;
    }

    /**
     * Distributes a credit-event amount across installments in original
     * sequence order without exceeding the uncredited amount of each one.
     *
     * @param  list<array{id: int, amount_centavos: int, credited_centavos: int}>  $installments
     * @return list<array{id: int, amount_centavos: int}>
     */
    public function allocateCredit(array $installments, int $creditCentavos): array
    {
        if ($creditCentavos < CreditCardMoney::MIN_AMOUNT_CENTAVOS) {
            throw new InvalidArgumentException('Credit event must be a positive centavo amount.');
        }

        $remaining = $creditCentavos;
        $applications = [];

        foreach ($installments as $installment) {
            if ($remaining === 0) {
                break;
            }

            $available = max(0, $installment['amount_centavos'] - $installment['credited_centavos']);
            if ($available === 0) {
                continue;
            }

            $applied = min($available, $remaining);
            $applications[] = ['id' => $installment['id'], 'amount_centavos' => $applied];
            $remaining -= $applied;
        }

        if ($remaining !== 0) {
            throw new InvalidArgumentException('Credit event exceeds the uncredited purchase amount.');
        }

        return $applications;
    }
}
