<?php

declare(strict_types=1);

namespace App\Http\Resources\RecurringTransactions;

use App\Models\CreditCard;
use App\Models\RecurringCardOccurrence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RecurringCardOccurrence */
final class CardOccurrenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RecurringCardOccurrence $occurrence */
        $occurrence = $this->resource;
        $occurrence->loadMissing(['originalCreditCard', 'overrideCreditCard', 'originalCategory', 'overrideCategory', 'purchase']);
        $snapshot = $occurrence->card_identity_snapshot ?? [];

        return [
            'id' => $occurrence->id,
            'scheduled_date' => $occurrence->scheduled_date?->toDateString(),
            'state' => $occurrence->state->value,
            'generation_mode' => $occurrence->generation_mode_snapshot->value,
            'scheduled_amount_centavos' => $occurrence->scheduled_amount_centavos,
            'description' => $occurrence->description_snapshot,
            'notes' => $occurrence->notes_snapshot,
            'original_card' => [
                'id' => $occurrence->credit_card_id_original,
                'name' => $snapshot['name'] ?? null,
                'institution_name' => $snapshot['institution_name'] ?? null,
                'last_four' => $snapshot['last_four'] ?? null,
                'status' => $snapshot['status'] ?? null,
            ],
            'original_category' => [
                'id' => $occurrence->category_id_original,
                'name' => $occurrence->category_name_snapshot,
            ],
            'card' => $this->cardSummary($occurrence->overrideCreditCard ?? $occurrence->originalCreditCard),
            'category' => $this->categorySummary($occurrence),
            'actual_amount_centavos' => $occurrence->actual_amount_centavos,
            'actual_purchase_date' => $occurrence->actual_purchase_date?->toDateString(),
            'purchase_id' => $occurrence->purchase?->id,
            'failure_code' => $occurrence->failure_code,
            'recorded_at' => $occurrence->recorded_at?->toIso8601String(),
            'dismissed_at' => $occurrence->dismissed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function cardSummary(?CreditCard $card): ?array
    {
        if (! $card instanceof CreditCard) {
            return null;
        }

        return [
            'id' => $card->id,
            'name' => $card->name,
            'institution_name' => $card->institution_name,
            'last_four' => $card->last_four,
            'status' => $card->status->value,
        ];
    }

    /** @return array<string, mixed> */
    private function categorySummary(RecurringCardOccurrence $occurrence): array
    {
        $category = $occurrence->overrideCategory ?? $occurrence->originalCategory;

        return [
            'id' => $occurrence->category_id_override ?? $occurrence->category_id_original,
            'name' => $category?->name ?? $occurrence->category_name_snapshot,
        ];
    }
}
