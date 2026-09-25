<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Enums\RecurringTransactions\RecurrenceDestinationType;
use App\Models\RecurringCardOccurrence;
use App\Models\RecurringTransaction;
use App\Services\RecurringTransactions\RecurringDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class RecurringCardRuleContractTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function create_show_and_list_expose_card_identity_destination_and_mode(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $startDate = RecurringDateRange::businessDate();

        $created = $this->postJson('/api/v1/recurring-transactions', $this->cardPayload($card, $category, [
            'start_date' => $startDate,
            'generation_mode' => CardGenerationMode::Confirmation->value,
        ]), ['Idempotency-Key' => 'card-create-1'])
            ->assertCreated()
            ->assertJsonPath('data.destination_type', RecurrenceDestinationType::CreditCard->value)
            ->assertJsonPath('data.generation_mode', CardGenerationMode::Confirmation->value)
            ->assertJsonPath('data.financial_account', null)
            ->assertJsonPath('data.credit_card.id', $card->id)
            ->assertJsonPath('data.credit_card.last_four', $card->last_four)
            ->assertJsonPath('data.next_expected_occurrence', $startDate);

        $this->getJson('/api/v1/recurring-transactions/'.$created->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.destination_type', RecurrenceDestinationType::CreditCard->value)
            ->assertJsonPath('data.credit_card.id', $card->id);

        $this->getJson('/api/v1/recurring-transactions')
            ->assertOk()
            ->assertJsonPath('data.0.destination_type', RecurrenceDestinationType::CreditCard->value);
    }

    #[Test]
    public function omitted_destination_defaults_to_automatic_card_generation(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);

        $payload = $this->cardPayload($card, $category);
        unset($payload['generation_mode']);

        $this->postJson('/api/v1/recurring-transactions', $payload, ['Idempotency-Key' => 'card-default-mode-2'])
            ->assertCreated()
            ->assertJsonPath('data.generation_mode', CardGenerationMode::Automatic->value);
    }

    #[Test]
    public function list_and_detail_count_only_card_occurrences_that_need_review(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $rule = $this->cardRule($user, $card, ['generation_mode' => CardGenerationMode::Confirmation]);
        $date = CarbonImmutable::parse(RecurringDateRange::businessDate());

        foreach (CardOccurrenceState::cases() as $index => $state) {
            RecurringCardOccurrence::query()->create([
                'user_id' => $user->id,
                'recurring_transaction_id' => $rule->id,
                'scheduled_date' => $date->addDays($index)->toDateString(),
                'generation_mode_snapshot' => CardGenerationMode::Confirmation,
                'scheduled_amount_centavos' => 15_000,
                'description_snapshot' => 'Academia',
                'category_id_original' => $rule->category_id,
                'credit_card_id_original' => $card->id,
                'card_identity_snapshot' => ['name' => $card->name],
                'category_name_snapshot' => 'Academia',
                'state' => $state,
            ]);
        }

        $this->getJson('/api/v1/recurring-transactions')
            ->assertOk()
            ->assertJsonPath('data.0.generated_occurrence_count', 5)
            ->assertJsonPath('data.0.reviewable_occurrence_count', 3);

        RecurringCardOccurrence::query()
            ->where('recurring_transaction_id', $rule->id)
            ->where('state', CardOccurrenceState::Expected)
            ->firstOrFail()
            ->update(['state' => CardOccurrenceState::Recorded]);

        $this->getJson('/api/v1/recurring-transactions/'.$rule->id)
            ->assertOk()
            ->assertJsonPath('data.reviewable_occurrence_count', 2);
    }

    #[Test]
    public function legacy_account_rule_is_created_and_read_unchanged(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);

        $created = $this->postJson('/api/v1/recurring-transactions', $this->payload($account, $category), ['Idempotency-Key' => 'legacy-1'])
            ->assertCreated()
            ->assertJsonPath('data.destination_type', RecurrenceDestinationType::FinancialAccount->value)
            ->assertJsonPath('data.financial_account.id', $account->id)
            ->assertJsonPath('data.credit_card', null)
            ->assertJsonPath('data.generation_mode', null);

        self::assertSame(1, RecurringTransaction::count());
        self::assertSame(500_000, $account->refresh()->current_balance_centavos);
        self::assertSame(0, $account->transactions()->count());

        $this->getJson('/api/v1/recurring-transactions/'.$created->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.destination_type', RecurrenceDestinationType::FinancialAccount->value)
            ->assertJsonPath('data.reviewable_occurrence_count', 0);
    }

    #[Test]
    public function saving_a_card_rule_creates_no_purchase_or_balance_effect(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);

        $this->postJson('/api/v1/recurring-transactions', $this->cardPayload($card, $category), ['Idempotency-Key' => 'card-no-finance'])
            ->assertCreated();

        self::assertSame(0, $card->purchases()->count());
        self::assertSame(0, $card->statements()->count());
    }
}
