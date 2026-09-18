<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialDashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared Brazilian-real money shape used by every dashboard projection.
 *
 * @property array{amount_centavos?: int, currency_code?: string} $resource
 */
final class MoneyResource extends JsonResource
{
    /** @return array{amount_centavos: int, currency_code: string} */
    public function toArray(Request $request): array
    {
        return self::shape(
            (int) ($this->resource['amount_centavos'] ?? 0),
            (string) ($this->resource['currency_code'] ?? 'BRL'),
        );
    }

    /** @return array{amount_centavos: int, currency_code: string} */
    public static function shape(int $amountCentavos, string $currencyCode = 'BRL'): array
    {
        return [
            'amount_centavos' => $amountCentavos,
            'currency_code' => $currencyCode,
        ];
    }
}
