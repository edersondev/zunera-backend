<?php

declare(strict_types=1);

namespace App\Http\Requests\Categories;

use App\Data\Categories\UpdateCategoryData;
use App\Enums\Categories\CategoryClassification;
use App\Services\Categories\CategoryVisualOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCategoryRequest extends FormRequest
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
        return ['name' => ['sometimes', 'required', 'string', 'min:1', 'max:120'], 'classification' => ['sometimes', 'required', Rule::enum(CategoryClassification::class)], 'color' => ['sometimes', 'nullable', Rule::in(CategoryVisualOptions::colors())], 'icon' => ['sometimes', 'nullable', Rule::in(CategoryVisualOptions::icons())]];
    }

    public function toData(): UpdateCategoryData
    {
        $validated = $this->validated();
        if (array_key_exists('classification', $validated)) {
            $validated['classification'] = CategoryClassification::from((string) $validated['classification']);
        }

        return new UpdateCategoryData($validated);
    }
}
