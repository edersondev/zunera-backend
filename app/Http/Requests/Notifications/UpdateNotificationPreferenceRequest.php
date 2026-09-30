<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = [
            'enabled' => ['required', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_bool($value)) {
                    $fail('The enabled field must be a boolean.');
                }
            }],
        ];

        return $rules;
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function ($validator): void {
            foreach (array_keys($this->all()) as $key) {
                if ($key !== 'enabled') {
                    $validator->errors()->add($key, 'Unsupported field.');
                }
            }
        }];
    }

    public function enabled(): bool
    {
        return (bool) $this->validated('enabled');
    }
}
