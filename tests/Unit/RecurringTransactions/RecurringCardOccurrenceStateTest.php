<?php

declare(strict_types=1);

namespace Tests\Unit\RecurringTransactions;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\RecurringCardOccurrence;
use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecurringCardOccurrenceStateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('stateTransitions')]
    public function state_transitions_expose_the_documented_actionability(CardOccurrenceState $state, bool $actionable, bool $recorded, bool $dismissed): void
    {
        self::assertSame($actionable, $state->isActionable());
        self::assertSame($recorded, $state->isRecorded());
        self::assertSame($dismissed, $state->isDismissed());
    }

    /** @return iterable<string, array{0: CardOccurrenceState, 1: bool, 2: bool, 3: bool}> */
    public static function stateTransitions(): iterable
    {
        yield 'expected' => [CardOccurrenceState::Expected, true, false, false];
        yield 'awaiting_over_limit' => [CardOccurrenceState::AwaitingOverLimit, true, false, false];
        yield 'failed' => [CardOccurrenceState::Failed, true, false, false];
        yield 'dismissed' => [CardOccurrenceState::Dismissed, false, false, true];
        yield 'recorded' => [CardOccurrenceState::Recorded, false, true, false];
    }

    #[Test]
    public function occurrence_snapshot_fields_and_claim_metadata_round_trip_through_casts(): void
    {
        $user = User::factory()->create();
        $card = CreditCard::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $rule = RecurringTransaction::factory()->card($card)->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
        ]);

        $occurrence = RecurringCardOccurrence::query()->create([
            'user_id' => $user->id,
            'recurring_transaction_id' => $rule->id,
            'scheduled_date' => '2026-09-24',
            'generation_mode_snapshot' => CardGenerationMode::Confirmation,
            'scheduled_amount_centavos' => 15_000,
            'description_snapshot' => 'Academia',
            'notes_snapshot' => null,
            'category_id_original' => $category->id,
            'credit_card_id_original' => $card->id,
            'card_identity_snapshot' => ['name' => 'C6 Bank', 'institution_name' => 'C6 Bank', 'last_four' => '3450', 'status' => 'active'],
            'category_name_snapshot' => $category->name,
            'state' => CardOccurrenceState::Expected,
            'action_claim_key' => 'claim-1',
            'action_choice_version' => 4,
        ]);

        self::assertSame('2026-09-24', $occurrence->scheduled_date->toDateString());
        self::assertSame(CardGenerationMode::Confirmation, $occurrence->generation_mode_snapshot);
        self::assertSame(CardOccurrenceState::Expected, $occurrence->state);
        self::assertSame('C6 Bank', $occurrence->card_identity_snapshot['name']);
        self::assertSame(4, $occurrence->action_choice_version);
        self::assertSame($rule->id, $occurrence->recurringTransaction->id);
    }
}
