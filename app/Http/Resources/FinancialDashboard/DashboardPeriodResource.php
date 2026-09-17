<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialDashboard;

use App\Data\FinancialDashboard\DashboardPeriodData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property DashboardPeriodData $resource
 */
final class DashboardPeriodResource extends JsonResource
{
    /** @return array{preset: string, from: string, to: string} */
    public function toArray(Request $request): array
    {
        return self::shape($this->resource);
    }

    /** @return array{preset: string, from: string, to: string} */
    public static function shape(DashboardPeriodData $period): array
    {
        return $period->toArray();
    }
}
