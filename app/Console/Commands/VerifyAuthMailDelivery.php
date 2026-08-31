<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Authentication\AuthenticationMailDeliveryEvents;
use Illuminate\Console\Command;

final class VerifyAuthMailDelivery extends Command
{
    protected $signature = 'auth-mail:verify-delivery {--samples=100}';

    protected $description = 'Verify authentication mail provider delivery percentage.';

    public function handle(AuthenticationMailDeliveryEvents $events): int
    {
        $samples = max(1, (int) $this->option('samples'));
        $delivered = $events->deliveredCount($samples);
        $percent = ($delivered / $samples) * 100;

        $this->line(sprintf('Delivered %d/%d authentication messages (%.2f%%).', $delivered, $samples, $percent));

        return $percent >= 95.0 ? self::SUCCESS : self::FAILURE;
    }
}
