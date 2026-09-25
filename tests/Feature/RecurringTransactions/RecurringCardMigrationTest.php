<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringTransactions;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Enums\RecurringTransactions\RecurrenceDestinationType;
use App\Enums\Transactions\TransactionStatus;
use App\Enums\Transactions\TransactionType;
use App\Models\CreditCardPurchase;
use App\Models\RecurringCardOccurrence;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class RecurringCardMigrationTest extends RecurringTransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function existing_account_rows_backfill_to_financial_account_destination(): void
    {
        $user = $this->signInUser();
        $rule = $this->rule($user);

        self::assertSame(RecurrenceDestinationType::FinancialAccount, $rule->destinationType());
        self::assertNotNull($rule->financial_account_id);
        self::assertNull($rule->credit_card_id);
        self::assertNull($rule->generation_mode);
        self::assertTrue($rule->isAccountDestination());
    }

    #[Test]
    public function card_rules_enforce_exclusive_destination_ids(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $rule = $this->cardRule($user, $card);

        self::assertTrue($rule->isCardDestination());
        self::assertNull($rule->financial_account_id);
        self::assertSame($card->id, $rule->credit_card_id);
        self::assertSame(CardGenerationMode::Automatic, $rule->generation_mode);
    }

    #[Test]
    public function card_occurrence_date_is_unique_per_rule(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, ['category_id' => $category->id]);

        $base = [
            'user_id' => $user->id,
            'recurring_transaction_id' => $rule->id,
            'scheduled_date' => '2026-09-24',
            'generation_mode_snapshot' => CardGenerationMode::Automatic,
            'scheduled_amount_centavos' => 15_000,
            'description_snapshot' => 'Academia mensal',
            'notes_snapshot' => null,
            'category_id_original' => $category->id,
            'credit_card_id_original' => $card->id,
            'card_identity_snapshot' => ['name' => $card->name, 'institution_name' => $card->institution_name, 'last_four' => $card->last_four],
            'category_name_snapshot' => $category->name,
            'state' => CardOccurrenceState::Expected,
        ];

        RecurringCardOccurrence::query()->create($base);

        $this->expectException(QueryException::class);
        RecurringCardOccurrence::query()->create($base);
    }

    #[Test]
    public function purchase_source_is_nullable_and_unique_while_manual_purchases_stay_null(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, ['category_id' => $category->id]);

        $occurrence = RecurringCardOccurrence::query()->create([
            'user_id' => $user->id,
            'recurring_transaction_id' => $rule->id,
            'scheduled_date' => '2026-09-24',
            'generation_mode_snapshot' => CardGenerationMode::Automatic,
            'scheduled_amount_centavos' => 15_000,
            'description_snapshot' => 'Academia mensal',
            'notes_snapshot' => null,
            'category_id_original' => $category->id,
            'credit_card_id_original' => $card->id,
            'card_identity_snapshot' => ['name' => $card->name, 'institution_name' => $card->institution_name, 'last_four' => $card->last_four],
            'category_name_snapshot' => $category->name,
            'state' => CardOccurrenceState::Recorded,
        ]);

        $manual = CreditCardPurchase::factory()->forCard($card)->withCategory($category)->create(['user_id' => $user->id]);
        self::assertNull($manual->recurring_card_occurrence_id);

        $linked = CreditCardPurchase::factory()->forCard($card)->withCategory($category)->create([
            'user_id' => $user->id,
            'recurring_card_occurrence_id' => $occurrence->id,
        ]);
        self::assertSame($occurrence->id, $linked->recurring_card_occurrence_id);

        $this->expectException(QueryException::class);
        CreditCardPurchase::factory()->forCard($card)->withCategory($category)->create([
            'user_id' => $user->id,
            'recurring_card_occurrence_id' => $occurrence->id,
        ]);
    }

    #[Test]
    public function confirmation_claim_and_choice_version_persist(): void
    {
        $user = $this->signInUser();
        $card = $this->ownedCard($user);
        $category = $this->ownedCategory($user);
        $rule = $this->cardRule($user, $card, ['category_id' => $category->id]);

        $occurrence = RecurringCardOccurrence::query()->create([
            'user_id' => $user->id,
            'recurring_transaction_id' => $rule->id,
            'scheduled_date' => '2026-09-24',
            'generation_mode_snapshot' => CardGenerationMode::Confirmation,
            'scheduled_amount_centavos' => 15_000,
            'description_snapshot' => 'Academia mensal',
            'notes_snapshot' => null,
            'category_id_original' => $category->id,
            'credit_card_id_original' => $card->id,
            'card_identity_snapshot' => ['name' => $card->name, 'institution_name' => $card->institution_name, 'last_four' => $card->last_four],
            'category_name_snapshot' => $category->name,
            'state' => CardOccurrenceState::Expected,
            'action_claim_key' => 'claim-1',
            'action_choice_version' => 3,
        ]);

        self::assertSame('claim-1', $occurrence->action_claim_key);
        self::assertSame(3, $occurrence->action_choice_version);
    }

    #[Test]
    public function manual_purchases_and_account_occurrences_remain_unchanged(): void
    {
        $user = $this->signInUser();
        $account = $this->ownedAccount($user);
        $category = $this->ownedCategory($user);
        $rule = $this->rule($user, ['financial_account_id' => $account->id, 'category_id' => $category->id]);

        Transaction::query()->create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'category_id' => $category->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Pending,
            'description' => 'Assinatura',
            'amount_centavos' => 10_000,
            'transaction_date' => '2026-09-24',
            'recurring_transaction_id' => $rule->id,
            'recurrence_scheduled_date' => '2026-09-24',
        ]);

        self::assertSame(1, $rule->generatedOccurrences()->count());
        self::assertSame(0, RecurringCardOccurrence::query()->where('recurring_transaction_id', $rule->id)->count());
    }
}
