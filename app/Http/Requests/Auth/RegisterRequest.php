<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Data\Authentication\RegisterData;
use App\Services\Authentication\EmailNormalizer;
use App\Services\Authentication\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }

        if ($this->has('email')) {
            $this->merge(['email' => app(EmailNormalizer::class)->normalize((string) $this->input('email'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(PasswordRules $passwordRules): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => $passwordRules->confirmed(),
        ];
    }

    public function toData(): RegisterData
    {
        return new RegisterData(
            name: (string) $this->validated('name'),
            email: (string) $this->validated('email'),
            password: (string) $this->validated('password'),
        );
    }
}
