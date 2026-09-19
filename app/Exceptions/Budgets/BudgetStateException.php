<?php

declare(strict_types=1);

namespace App\Exceptions\Budgets;

use RuntimeException;

/**
 * Budget lifecycle conflicts. Every message stays safe to show to the signed-in
 * owner and never reveals whether another user's record exists.
 */
final class BudgetStateException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $errorCode,
        int $status,
    ) {
        parent::__construct($message, $status);
    }

    public static function monthAlreadyBudgeted(): self
    {
        return new self('A budget already exists for this month.', 'budget_month_conflict', 409);
    }

    public static function planConflict(): self
    {
        return new self('This category already has a plan in this monthly budget.', 'budget_plan_conflict', 409);
    }

    public static function planReadOnly(): self
    {
        return new self('This plan belongs to an archived category and can only be changed after the category is restored.', 'budget_plan_read_only', 409);
    }

    public static function categoryUnavailable(): self
    {
        return new self('A plan requires an active expense category available to the signed-in user.', 'budget_category_unavailable', 422);
    }

    public static function copySameMonth(): self
    {
        return new self('Choose a destination month different from the source month.', 'budget_copy_same_month', 422);
    }

    public static function copyDestinationOccupied(): self
    {
        return new self('The destination month already has a budget.', 'budget_copy_destination_occupied', 409);
    }

    public static function copyArchivedCategory(): self
    {
        return new self('This plan cannot be copied because its category is archived. Restore the category first.', 'budget_copy_archived_category', 409);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
