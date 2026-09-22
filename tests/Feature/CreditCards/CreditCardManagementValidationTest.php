<?php

declare(strict_types=1);

namespace Tests\Feature\CreditCards;

use App\Models\CreditCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreditCards\CreditCardFixtures;
use Tests\TestCase;

final class CreditCardManagementValidationTest extends TestCase
{
    use CreditCardFixtures;
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function invalidFieldCases(): array
    {
        return [
            'zero limit' => ['credit_limit_centavos', 0],
            'negative limit' => ['credit_limit_centavos', -100],
            'over precision limit' => ['credit_limit_centavos', 100.5],
            'limit above maximum' => ['credit_limit_centavos', 100_000_000_000],
            'non numeric limit' => ['credit_limit_centavos', 'mil'],
            'closing day zero' => ['closing_day', 0],
            'closing day above range' => ['closing_day', 32],
            'due day zero' => ['due_day', 0],
            'due day above range' => ['due_day', 32],
            'blank name' => ['name', ''],
            'name too long' => ['name', str_repeat('a', 101)],
            'blank institution' => ['institution_name', ''],
            'institution too long' => ['institution_name', str_repeat('b', 101)],
            'last four with letters' => ['last_four', '12a4'],
            'last four too short' => ['last_four', '123'],
            'last four too long' => ['last_four', '12345'],
            'unsupported color' => ['color', 'chartreuse'],
            'unsupported icon' => ['icon', 'unicorn'],
        ];
    }

    #[Test]
    #[DataProvider('invalidFieldCases')]
    public function invalid_card_values_are_rejected_with_field_feedback(string $field, mixed $value): void
    {
        $this->cardSignIn();

        $this->postJson('/api/v1/credit-cards', $this->payload([$field => $value]), ['Idempotency-Key' => 'invalid-'.$field])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame(0, CreditCard::count());
    }

    /** @return array<string, array{0: string}> */
    public static function credentialFieldCases(): array
    {
        return [
            'card number' => ['card_number'],
            'cvv' => ['cvv'],
            'pin' => ['pin'],
            'security code' => ['security_code'],
            'expiration date' => ['expiration_date'],
        ];
    }

    #[Test]
    #[DataProvider('credentialFieldCases')]
    public function sensitive_card_credentials_are_refused(string $field): void
    {
        $this->cardSignIn();

        $this->postJson('/api/v1/credit-cards', $this->payload([$field => '4111111111111111']), ['Idempotency-Key' => 'credential-'.$field])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame(0, CreditCard::count());
    }

    #[Test]
    public function missing_idempotency_key_is_rejected(): void
    {
        $this->cardSignIn();

        $this->postJson('/api/v1/credit-cards', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    #[Test]
    public function update_requires_at_least_one_field(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user);

        $this->patchJson('/api/v1/credit-cards/'.$card->id, [], ['Idempotency-Key' => 'empty-update'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function update_rejects_invalid_values_without_changing_the_card(): void
    {
        $user = $this->cardSignIn();
        $card = $this->activeCard($user, ['name' => 'Original', 'closing_day' => 10, 'due_day' => 17]);

        $this->patchJson('/api/v1/credit-cards/'.$card->id, ['credit_limit_centavos' => 0], ['Idempotency-Key' => 'bad-update-limit'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credit_limit_centavos');
        $this->patchJson('/api/v1/credit-cards/'.$card->id, ['closing_day' => 45], ['Idempotency-Key' => 'bad-update-day'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('closing_day');
        $this->patchJson('/api/v1/credit-cards/'.$card->id, ['last_four' => 'abcd'], ['Idempotency-Key' => 'bad-update-last-four'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('last_four');

        $card->refresh();
        $this->assertSame('Original', $card->name);
        $this->assertSame(10, $card->closing_day);
        $this->assertSame(17, $card->due_day);
    }

    #[Test]
    public function optional_visual_fields_default_when_omitted(): void
    {
        $this->cardSignIn();

        $this->postJson('/api/v1/credit-cards', $this->payload(['color' => null, 'icon' => null, 'last_four' => null]), ['Idempotency-Key' => 'visual-defaults'])
            ->assertCreated()
            ->assertJsonPath('data.color', 'violet')
            ->assertJsonPath('data.icon', 'credit_card')
            ->assertJsonPath('data.last_four', null);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nubank',
            'institution_name' => 'Nubank',
            'last_four' => '1234',
            'color' => 'violet',
            'icon' => 'credit_card',
            'credit_limit_centavos' => 500_000,
            'closing_day' => 10,
            'due_day' => 17,
        ], $overrides);
    }
}
