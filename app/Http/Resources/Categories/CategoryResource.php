<?php

declare(strict_types=1);

namespace App\Http\Resources\Categories;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
final class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'origin' => $this->origin->value,
            'name' => $this->name,
            'classification' => $this->classification->value,
            'color' => $this->color,
            'icon' => $this->icon,
            'status' => $this->status->value,
            'has_financial_transactions' => $this->has_financial_transactions,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
