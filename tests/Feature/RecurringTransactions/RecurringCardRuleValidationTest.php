<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\RecurrenceDestinationType;
use App\Enums\Transactions\TransactionType;
use App\Models\CreditCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class RecurringCardRuleValidationTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function foreign_or_inactive_card_is_rejected_without_disclosing_ownership(): void
    {
        $user = $this->signInUser();
        $category = $this->ownedCategory($user);
        $foreign = User::factory()->create();
        $foreignCard = $this->ownedCard($foreign);

        $this->postJson('/api/v1/recurring-transactions', $this->cardPayload($foreignCard, $category), ['Idempotency-Key' => 'foreign-card'])
            ->assertNotFound();

        $archived = CreditCard::factory()->archived()->create(['user_id' => $user->id]);
        $this->postJson('/api/v1/recurring-transactions', $this->cardPayload($archived, $category), ['Idempotency-Key' => 'archived-card'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credit_card_id']);
    }

    #[Test]
    public function destination_exclusivity_is_enforced_on_create(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);

        $this->postJson('/api/v1/recurring-transactions', $this->cardPayload($card, $category, [
            'financial_account_id' => $account->id,
        ]), ['Idempotency-Key' => 'both-dest'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['financial_account_id']);

        $this->postJson('/api/v1/recurring-transactions', $this->payload($account, $category, [
            'credit_card_id' => $card->id,
        ]), ['Idempotency-Key' => 'account-with-card'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credit_card_id']);
    }

    #[Test]
    public function card_rules_require_an_expense_category_and_type(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $incomeCategory = $this->ownedCategory($user, TransactionType::Income);

        $this->postJson('/api/v1/recurring-transactions', $this->cardPayload($card, $incomeCategory), ['Idempotency-Key' => 'card-income'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);

        $this->postJson('/api/v1/recurring-transactions', $this->cardPayload($card, $this->ownedCategory($user), [
            'type' => TransactionType::Income->value,
        ]), ['Idempotency-Key' => 'card-income-type'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    #[Test]
    public function destination_type_is_immutable_on_update(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $rule = $this->cardRule($user, $card);

        $this->patchJson('/api/v1/recurring-transactions/'.$rule->id, [
            'financial_account_id' => $this->ownedAccount($user)->id,
        ], ['Idempotency-Key' => 'card-to-account'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['financial_account_id']);

        $accountRule = $this->rule($user);
        $this->patchJson('/api/v1/recurring-transactions/'.$accountRule->id, [
            'credit_card_id' => $card->id,
        ], ['Idempotency-Key' => 'account-to-card'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credit_card_id']);
    }

    #[Test]
    public function card_filter_rejects_foreign_or_missing_ids_privacy_safely(): void
    {
        $user = $this->signInUser();
        $foreign = User::factory()->create();
        $foreignCard = $this->ownedCard($foreign);

        $this->getJson('/api/v1/recurring-transactions?credit_card_id='.$foreignCard->id)->assertNotFound();

        $this->getJson('/api/v1/recurring-transactions?destination_type='.RecurrenceDestinationType::CreditCard->value)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }
}
