<?php

declare(strict_types=1);

namespace App\Http\Resources\CreditCards;

/**
 * The detail payload keeps the same additive card shape as the list entry so
 * clients read one contract for both, including derived summary and current
 * statement.
 */
final class CreditCardDetailResource extends CreditCardResource {}
