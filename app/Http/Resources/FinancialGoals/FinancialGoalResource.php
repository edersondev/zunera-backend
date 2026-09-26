<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialGoals;

use App\Services\FinancialGoals\FinancialGoalQueryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FinancialGoalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return app(FinancialGoalQueryService::class)->project($this->resource);
    }
}
