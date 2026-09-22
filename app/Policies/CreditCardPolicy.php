<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CreditCard;
use App\Models\User;

/**
 * Ownership gate for the card aggregate. Foreign cards must stay
 * indistinguishable from absent ones, so every ability is a plain owner check
 * while services keep resolving cards through owner-scoped queries.
 */
final class CreditCardPolicy
{
    public function view(User $user, CreditCard $card): bool
    {
        return $this->owns($user, $card);
    }

    public function update(User $user, CreditCard $card): bool
    {
        return $this->owns($user, $card);
    }

    public function archive(User $user, CreditCard $card): bool
    {
        return $this->owns($user, $card);
    }

    public function restore(User $user, CreditCard $card): bool
    {
        return $this->owns($user, $card);
    }

    public function purchase(User $user, CreditCard $card): bool
    {
        return $this->owns($user, $card);
    }

    private function owns(User $user, CreditCard $card): bool
    {
        return (int) $card->user_id === (int) $user->id;
    }
}
