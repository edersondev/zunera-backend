<?php

declare(strict_types=1);

namespace App\Data\Authentication;

final readonly class RecoveryRequestData
{
    public function __construct(
        public string $email,
        public string $ipAddress,
    ) {}
}
