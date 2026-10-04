<?php

declare(strict_types=1);

namespace App\Http\Requests\AccountData;

use Illuminate\Foundation\Http\FormRequest;

final class ArchiveAccountDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];

        foreach (array_keys($this->all()) as $key) {
            $rules[$key] = ['missing'];
        }

        return $rules;
    }
}
