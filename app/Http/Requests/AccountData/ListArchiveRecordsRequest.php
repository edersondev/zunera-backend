<?php

declare(strict_types=1);

namespace App\Http\Requests\AccountData;

use App\Services\AccountData\AccountDataService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListArchiveRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'type' => ['required', 'string', Rule::in(AccountDataService::RECORD_TYPES)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];

        foreach (array_keys($this->all()) as $key) {
            if (! array_key_exists($key, $rules)) {
                $rules[$key] = ['missing'];
            }
        }

        return $rules;
    }
}
