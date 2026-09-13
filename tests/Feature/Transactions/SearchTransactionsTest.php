<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class SearchTransactionsTest extends TransactionFeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function search_is_case_and_accent_insensitive_across_description_and_notes(): void
    {
        $user = $this->signInUser();
        $match = $this->transaction($user, 'expense', ['description' => 'Almoço', 'notes' => 'Refeição no centro']);
        $this->transaction($user, 'expense', ['description' => 'Mercado', 'notes' => null]);
        $this->getJson('/api/v1/transactions?q=ALMOCO')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $match->id);
    }

    #[Test]
    public function search_matches_description_and_notes_with_accents_and_case_variations(): void
    {
        $user = $this->signInUser();
        $byDescription = $this->transaction($user, 'expense', ['description' => 'Almoço', 'notes' => 'Refeição no centro']);
        $byNotes = $this->transaction($user, 'expense', ['description' => 'Mercado', 'notes' => 'Compra de café']);
        $this->transaction($user, 'expense', ['description' => 'Transporte', 'notes' => null]);

        foreach (['almoço', 'ALMOCO', 'Almo'] as $term) {
            $this->getJson('/api/v1/transactions?q='.rawurlencode($term))->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $byDescription->id);
        }
        $this->getJson('/api/v1/transactions?q=REFEICAO')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $byDescription->id);
        $this->getJson('/api/v1/transactions?q=cafe')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $byNotes->id);
    }

    #[Test]
    public function search_combines_with_filters_and_returns_an_empty_result_without_matches(): void
    {
        $user = $this->signInUser();
        $income = $this->transaction($user, 'income', ['description' => 'Salário', 'transaction_date' => '2026-09-11']);
        $this->transaction($user, 'expense', ['description' => 'Salário do sócio', 'transaction_date' => '2026-09-11']);
        $this->transaction($user, 'income', ['description' => 'Freelance', 'transaction_date' => '2026-09-10']);

        $this->getJson('/api/v1/transactions?q=salario&type=income&from=11/09/2026&to=11/09/2026')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $income->id);
        $this->getJson('/api/v1/transactions?q=inexistente')->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    }
}
