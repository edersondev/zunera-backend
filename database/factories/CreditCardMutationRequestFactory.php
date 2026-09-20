<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CreditCardMutationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditCardMutationRequest> */
class CreditCardMutationRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => fake()->unique()->uuid(),
            'operation' => 'card.create',
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'target_type' => 'credit_card',
            'target_id' => null,
            'response_status' => 201,
            'response_body' => ['data' => []],
            'completed_at' => now(),
        ];
    }
}
