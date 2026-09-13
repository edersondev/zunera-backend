<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class ViewTransactionDetailsTest extends TransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function details_include_association_summaries_and_hide_other_owners(): void
    {
        $user = $this->signInUser();
        $own = $this->transaction($user, 'income');
        $foreign = $this->transaction(User::factory()->create());

        $this->getJson("/api/v1/transactions/{$own->id}")->assertOk()
            ->assertJsonPath('data.financial_account.id', $own->financial_account_id)
            ->assertJsonPath('data.category.id', $own->category_id);
        $this->getJson("/api/v1/transactions/{$foreign->id}")->assertNotFound();
    }

    #[Test]
    public function guest_cannot_view_transaction_details(): void
    {
        $owner = User::factory()->create();
        $transaction = $this->transaction($owner);
        $this->getJson("/api/v1/transactions/{$transaction->id}")->assertUnauthorized();
    }
}
