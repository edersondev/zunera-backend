<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialDashboard;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Category snapshot including the archived status needed by historical
 * expense distribution.
 *
 * @property Category $resource
 */
final class DashboardCategoryResource extends JsonResource
{
    /** @return array{id: int, name: string, classification: string, status: string} */
    public function toArray(Request $request): array
    {
        return self::shape($this->resource);
    }

    /**
     * @param  Category|array{id: int|string, name: string, classification: string, status: string}  $category
     * @return array{id: int, name: string, classification: string, status: string}
     */
    public static function shape(Category|array $category): array
    {
        if (is_array($category)) {
            return [
                'id' => (int) $category['id'],
                'name' => (string) $category['name'],
                'classification' => (string) $category['classification'],
                'status' => (string) $category['status'],
            ];
        }

        return [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
            'classification' => $category->classification->value,
            'status' => $category->status->value,
        ];
    }
}
