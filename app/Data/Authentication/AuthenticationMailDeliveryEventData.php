<?php

declare(strict_types=1);

namespace App\Data\Authentication;

use Carbon\CarbonImmutable;

final readonly class AuthenticationMailDeliveryEventData
{
    public function __construct(
        public string $eventId,
        public string $messageId,
        public string $status,
        public CarbonImmutable $occurredAt,
    ) {}
}
