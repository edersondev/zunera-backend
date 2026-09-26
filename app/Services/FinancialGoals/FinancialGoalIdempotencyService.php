<?php

declare(strict_types=1);

namespace App\Services\FinancialGoals;

use App\Exceptions\FinancialGoals\FinancialGoalStateException;
use Illuminate\Support\Facades\DB;

final class FinancialGoalIdempotencyService
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(): array{goal_id: int, status: int, body: array<string, mixed>}  $mutation
     * @return array{status: int, body: array<string, mixed>}
     */
    public function execute(int $userId, string $key, string $operation, array $payload, callable $mutation): array
    {
        $fingerprint = hash('sha256', json_encode([$operation, $payload], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($userId, $key, $operation, $fingerprint, $mutation): array {
            $now = now();
            $claimed = DB::table('financial_goal_mutation_requests')->insertOrIgnore([
                'user_id' => $userId,
                'idempotency_key' => $key,
                'operation' => $operation,
                'request_fingerprint' => $fingerprint,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($claimed === 0) {
                $existing = DB::table('financial_goal_mutation_requests')->where('user_id', $userId)->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing === null) {
                    throw new \LogicException('Goal mutation key claim could not be read.');
                }
                if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw FinancialGoalStateException::conflict('idempotency_key_reused', 'Idempotency-Key was already used for a different request.');
                }

                return ['status' => (int) $existing->response_status, 'body' => json_decode((string) $existing->response_body, true, flags: JSON_THROW_ON_ERROR)];
            }
            $result = $mutation();
            DB::table('financial_goal_mutation_requests')->where('user_id', $userId)->where('idempotency_key', $key)->update([
                'financial_goal_id' => $result['goal_id'],
                'response_status' => $result['status'],
                'response_body' => json_encode($result['body'], JSON_THROW_ON_ERROR),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            return ['status' => $result['status'], 'body' => $result['body']];
        }, 3);
    }
}
