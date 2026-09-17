<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RecurringTransactions\RecurringOccurrenceService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ProcessRecurringTransactions extends Command
{
    protected $signature = 'recurring:process-due {--date= : Business date to process, YYYY-MM-DD}';

    protected $description = 'Create pending occurrences for due recurring transactions and end expired rules.';

    public function handle(RecurringOccurrenceService $service): int
    {
        $date = $this->option('date');
        try {
            $result = $service->processDueRules(is_string($date) && $date !== '' ? $date : null);
        } catch (ValidationException $exception) {
            foreach ($exception->errors()['date'] ?? [] as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        $this->info(sprintf(
            'Processed %d recurring transaction(s): %d pending occurrence(s) created, %d rule(s) ended.',
            $result['processed'],
            $result['created'],
            $result['ended'],
        ));

        return self::SUCCESS;
    }
}
