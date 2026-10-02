<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Authentication\EmailNormalizer;
use Illuminate\Foundation\Http\FormRequest;

final class ConfirmAccountActivationRequest extends FormRequest
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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }
}
