<?php

declare(strict_types=1);

namespace App\Http\Resources\Budgets;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Brazilian-real money shape shared by every budget projection. Amounts stay
 * integer centavos end to end; the client never receives a float.
 *
 * @property array{amount_centavos?: int, currency_code?: string} $resource
 */
final class BudgetMoneyResource extends JsonResource
{
    /** @return array{amount_centavos: int, currency_code: string} */
    public function toArray(Request $request): array
    {
        return self::shape((int) ($this->resource['amount_centavos'] ?? 0));
    }

    /** @return array{amount_centavos: int, currency_code: string} */
    public static function shape(int $amountCentavos, string $currencyCode = 'BRL'): array
    {
        return ['amount_centavos' => $amountCentavos, 'currency_code' => $currencyCode];
    }
}
