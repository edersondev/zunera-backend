<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Data\Authentication\AuthenticationMailDeliveryEventData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class StoreAuthenticationMailDeliveryEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'min:16', 'max:255'],
            'message_id' => ['required', 'uuid', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'],
            'status' => ['required', 'in:delivered,bounced,deferred,rejected'],
            'occurred_at' => ['required', 'date'],
            'recipient' => ['prohibited'],
            'email' => ['prohibited'],
            'to' => ['prohibited'],
            'subject' => ['prohibited'],
            'body' => ['prohibited'],
            'token' => ['prohibited'],
        ];
    }

    public function validateSignature(): void
    {
        $secret = (string) config('authentication.mail.delivery_secret');
        $timestamp = (string) $this->header('X-Zunera-Timestamp', '');
        $signature = (string) $this->header('X-Zunera-Signature', '');

        if ($secret === '' || ! preg_match('/^\d+$/', $timestamp)) {
            $this->failSignature();
        }

        if (abs(now()->timestamp - (int) $timestamp) > (int) config('authentication.mail.delivery_replay_seconds', 300)) {
            $this->failSignature();
        }

        if (! preg_match('/^v1=([0-9a-f]{64})$/', $signature, $matches)) {
            $this->failSignature();
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$this->getContent(), $secret);

        if (! hash_equals($expected, $matches[1])) {
            $this->failSignature();
        }
    }

    public function toData(): AuthenticationMailDeliveryEventData
    {
        return new AuthenticationMailDeliveryEventData(
            eventId: (string) $this->validated('event_id'),
            messageId: (string) $this->validated('message_id'),
            status: (string) $this->validated('status'),
            occurredAt: CarbonImmutable::parse((string) $this->validated('occurred_at')),
        );
    }

    private function failSignature(): never
    {
        abort(401, 'Invalid authentication mail delivery signature.');
    }
}
