<?php

declare(strict_types=1);

namespace App\Http\Resources\Transactions;

use App\Data\Transactions\TransactionResponseData;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transaction */
final class TransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return TransactionResponseData::from($this->resource);
    }
}
