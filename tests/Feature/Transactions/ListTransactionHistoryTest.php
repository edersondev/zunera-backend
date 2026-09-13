<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class ListTransactionHistoryTest extends TransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_receives_newest_first_paginated_history_only(): void
    {
        $user = $this->signInUser();
        $older = $this->transaction($user, 'expense', ['transaction_date' => today()->subDay(), 'description' => 'Ontem']);
        $newer = $this->transaction($user, 'income', ['transaction_date' => today(), 'description' => 'Hoje']);
        $foreign = $this->transaction(User::factory()->create());

        $this->getJson('/api/v1/transactions?per_page=50')->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('data.0.financial_account.id', $newer->financial_account_id)
            ->assertJsonPath('data.0.category.id', $newer->category_id)
            ->assertJsonMissing(['id' => $foreign->id]);
        $this->getJson('/api/v1/transactions?per_page=51')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }
}
