<?php

declare(strict_types=1);

namespace App\Http\Requests\Categories;

use App\Data\Categories\CreateCategoryData;
use App\Enums\Categories\CategoryClassification;
use App\Services\Categories\CategoryVisualOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'min:1', 'max:120'], 'classification' => ['required', Rule::enum(CategoryClassification::class)], 'color' => ['nullable', Rule::in(CategoryVisualOptions::colors())], 'icon' => ['nullable', Rule::in(CategoryVisualOptions::icons())]];
    }

    public function toData(): CreateCategoryData
    {
        $validated = $this->validated();

        return new CreateCategoryData((int) $this->user()->id, (string) $validated['name'], CategoryClassification::from((string) $validated['classification']), $validated['color'] ?? null, $validated['icon'] ?? null);
    }
}
