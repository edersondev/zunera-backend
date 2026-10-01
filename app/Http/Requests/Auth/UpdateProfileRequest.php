<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['name' => ['required', 'string', 'min:2', 'max:255']];

        foreach (array_keys($this->all()) as $key) {
            if ($key !== 'name') {
                $rules[$key] = ['missing'];
            }
        }

        return $rules;
    }
}
