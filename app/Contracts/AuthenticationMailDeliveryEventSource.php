<?php

declare(strict_types=1);

namespace App\Contracts;

interface AuthenticationMailDeliveryEventSource
{
    public function deliveredCount(int $sampleSize): int;
}
