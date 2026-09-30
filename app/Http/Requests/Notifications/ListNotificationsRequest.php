<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'view' => ['sometimes', 'string', Rule::in(['all', 'unread', 'requires_action'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'string', 'max:512'],
        ];
    }

    public function view(): string
    {
        return (string) $this->validated('view', 'all');
    }

    public function limit(): int
    {
        return (int) $this->validated('limit', 25);
    }

    public function cursor(): ?string
    {
        return $this->validated('cursor');
    }
}
