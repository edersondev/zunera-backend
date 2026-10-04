<?php

declare(strict_types=1);

namespace App\Http\Requests\AccountData;

use Illuminate\Foundation\Http\FormRequest;

final class DeleteAccountDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['current_password' => ['required', 'string', 'max:1024']];

        foreach (array_keys($this->all()) as $key) {
            if (! array_key_exists($key, $rules)) {
                $rules[$key] = ['missing'];
            }
        }

        return $rules;
    }
}
