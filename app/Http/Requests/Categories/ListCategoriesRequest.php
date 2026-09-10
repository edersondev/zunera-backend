<?php

declare(strict_types=1);

namespace App\Http\Requests\Categories;

use App\Enums\Categories\CategoryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['status' => ['sometimes', 'required', Rule::enum(CategoryStatus::class)]];
    }

    public function status(): CategoryStatus
    {
        return CategoryStatus::from((string) $this->validated('status', CategoryStatus::Active->value));
    }
}
