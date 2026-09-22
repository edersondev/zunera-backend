<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardPurchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditCardPurchase> */
class CreditCardPurchaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'credit_card_id' => CreditCard::factory(),
            'category_id' => Category::factory(),
            'description' => fake()->sentence(3),
            'notes' => fake()->optional()->sentence(),
            'total_amount_centavos' => fake()->numberBetween(100, 500_000),
            'installment_count' => 1,
            'purchase_date' => today()->toDateString(),
            'currency_code' => 'BRL',
            'card_name_snapshot' => 'Cartão',
            'category_name_snapshot' => 'Categoria',
            'category_status_snapshot' => 'active',
        ];
    }

    public function forCard(CreditCard $card): static
    {
        return $this->state([
            'user_id' => $card->user_id,
            'credit_card_id' => $card->id,
            'card_name_snapshot' => $card->name,
        ]);
    }

    public function withCategory(Category $category): static
    {
        return $this->state([
            'category_id' => $category->id,
            'category_name_snapshot' => $category->name,
            'category_status_snapshot' => $category->status->value,
        ]);
    }

    public function installments(int $count, ?int $totalCentavos = null): static
    {
        return $this->state([
            'installment_count' => $count,
            'total_amount_centavos' => $totalCentavos ?? $count * 10_000,
        ]);
    }
}
