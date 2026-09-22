<?php

declare(strict_types=1);

namespace App\Services\CreditCards;

use App\Exceptions\CreditCards\CreditCardStateException;
use Illuminate\Support\Facades\DB;

/**
 * Persists one owner/key/fingerprint record per accepted Credit Cards mutation
 * and replays the stored response for an identical retry. A different request
 * behind the same key is rejected, and an explicit over-limit confirmation is
 * expected to arrive as a brand-new mutation with its own key.
 */
final class CreditCardMutationIdempotencyService
{
    /**
     * @param  callable(): array{target_type: string, target_id: int, status: int, response: array<string, mixed>}  $mutation
     * @return array{target_type: string, target_id: int, status: int, response: array<string, mixed>, replayed: bool}
     */
    public function run(int $userId, string $key, string $operation, string $fingerprint, callable $mutation): array
    {
        $existing = DB::table('credit_card_mutation_requests')
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                throw CreditCardStateException::idempotencyKeyReused();
            }

            $payload = $existing->response_body === null
                ? []
                : json_decode((string) $existing->response_body, true, flags: JSON_THROW_ON_ERROR);

            return [
                'target_type' => (string) ($existing->target_type ?? ''),
                'target_id' => (int) ($existing->target_id ?? 0),
                'status' => (int) ($existing->response_status ?? 200),
                'response' => is_array($payload) ? $payload : [],
                'replayed' => true,
            ];
        }

        $result = $mutation();

        DB::table('credit_card_mutation_requests')->insert([
            'user_id' => $userId,
            'idempotency_key' => $key,
            'operation' => $operation,
            'request_fingerprint' => $fingerprint,
            'target_type' => $result['target_type'],
            'target_id' => $result['target_id'],
            'response_status' => $result['status'],
            'response_body' => json_encode($result['response'], JSON_THROW_ON_ERROR),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [...$result, 'replayed' => false];
    }

    /** @param array<string, mixed> $payload */
    public function fingerprint(string $operation, array $payload): string
    {
        ksort($payload);

        return hash('sha256', $operation.'|'.json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
