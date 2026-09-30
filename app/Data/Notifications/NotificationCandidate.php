<?php

declare(strict_types=1);

namespace App\Data\Notifications;

use Carbon\CarbonImmutable;

final readonly class NotificationCandidate
{
    /**
     * @param  array<string, int|string>  $identity  Immutable stage, period, or target fields only.
     * @param  array<string, mixed>  $snapshot  Safe event-time presentation values.
     * @param  array<string, mixed>  $currentContext  Safe source-derived current values.
     */
    public function __construct(
        public int $userId,
        public string $type,
        public string $category,
        public string $severity,
        public string $sourceKind,
        public int $sourceId,
        public array $identity,
        public CarbonImmutable $eventAt,
        public array $snapshot = [],
        public array $currentContext = [],
        public bool $actionable = true,
    ) {}
}
