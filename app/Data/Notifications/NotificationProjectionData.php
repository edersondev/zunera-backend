<?php

declare(strict_types=1);

namespace App\Data\Notifications;

use Carbon\CarbonImmutable;

final readonly class NotificationProjectionData
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public int $userId,
        public string $sourceKind,
        public ?int $sourceId = null,
        public ?int $affectedYear = null,
        public ?int $affectedMonth = null,
        public ?string $qualifiedType = null,
        public ?CarbonImmutable $qualifiedAt = null,
        public array $context = [],
    ) {}
}
