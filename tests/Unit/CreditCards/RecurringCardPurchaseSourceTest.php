<?php

declare(strict_types=1);

namespace Tests\Unit\CreditCards;

use App\Enums\RecurringTransactions\CardGenerationMode;
use App\Enums\RecurringTransactions\CardOccurrenceState;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardPurchase;
use App\Models\RecurringCardOccurrence;
use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecurringCardPurchaseSourceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function recurring_purchases_carry_a_single_installment_and_a_source_link(): void
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
            'generation_mode_snapshot' => CardGenerationMode::Automatic,
            'scheduled_amount_centavos' => 15_000,
            'description_snapshot' => 'Academia',
            'notes_snapshot' => null,
            'category_id_original' => $category->id,
            'credit_card_id_original' => $card->id,
            'card_identity_snapshot' => ['name' => $card->name, 'institution_name' => $card->institution_name, 'last_four' => $card->last_four, 'status' => 'active'],
            'category_name_snapshot' => $category->name,
            'state' => CardOccurrenceState::Recorded,
        ]);

        $purchase = CreditCardPurchase::factory()->forCard($card)->withCategory($category)->create([
            'user_id' => $user->id,
            'recurring_card_occurrence_id' => $occurrence->id,
            'installment_count' => 1,
        ]);

        self::assertSame(1, $purchase->installment_count);
        self::assertSame($occurrence->id, $purchase->recurringCardOccurrence->id);
        self::assertSame($rule->id, $purchase->recurringCardOccurrence->recurring_transaction_id);
        self::assertSame('2026-09-24', $purchase->recurringCardOccurrence->scheduled_date->toDateString());
    }

    #[Test]
    public function manual_purchases_keep_a_null_source_and_preserve_their_date(): void
    {
        $user = User::factory()->create();
        $card = CreditCard::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);

        $purchase = CreditCardPurchase::factory()->forCard($card)->withCategory($category)->create([
            'user_id' => $user->id,
            'purchase_date' => '2026-09-20',
        ]);

        self::assertNull($purchase->recurring_card_occurrence_id);
        self::assertSame('2026-09-20', $purchase->purchase_date->toDateString());
    }
}
