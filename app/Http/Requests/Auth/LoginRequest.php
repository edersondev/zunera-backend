<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Data\Authentication\LoginData;
use App\Services\Authentication\EmailNormalizer;
use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
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
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function toData(): LoginData
    {
        return new LoginData(
            email: (string) $this->validated('email'),
            password: (string) $this->validated('password'),
            ipAddress: (string) $this->ip(),
        );
    }
}
