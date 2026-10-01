<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Authentication\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(PasswordRules $passwordRules): array
    {
        $rules = [
            'current_password' => ['required', 'string'],
            'password' => $passwordRules->confirmed(),
            'password_confirmation' => ['required', 'string'],
        ];

        foreach (array_keys($this->all()) as $key) {
            if (! array_key_exists($key, $rules)) {
                $rules[$key] = ['missing'];
            }
        }

        return $rules;
    }
}
