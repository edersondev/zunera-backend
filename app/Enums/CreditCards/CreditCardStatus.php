<?php

declare(strict_types=1);

namespace App\Enums\CreditCards;

enum CreditCardStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
