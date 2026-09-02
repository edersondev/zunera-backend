<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Data\Authentication\ResetPasswordData;
use App\Services\Authentication\EmailNormalizer;
use App\Services\Authentication\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
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
            'email' => ['required', 'email:rfc', 'max:255'],
            'token' => ['required', 'string'],
            'password' => $passwordRules->confirmed(),
        ];
    }

    public function toData(): ResetPasswordData
    {
        return new ResetPasswordData(
            email: (string) $this->validated('email'),
            token: (string) $this->validated('token'),
            password: (string) $this->validated('password'),
        );
    }
}
