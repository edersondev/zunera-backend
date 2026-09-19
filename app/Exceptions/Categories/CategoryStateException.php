<?php

namespace App\Exceptions\Categories;

use RuntimeException;

final class CategoryStateException extends RuntimeException
{
    public static function readOnlySystemDefault(): self
    {
        return new self('System default categories are read-only.', 409);
    }

    public static function alreadyArchived(): self
    {
        return new self('This category is already archived.', 409);
    }

    public static function alreadyActive(): self
    {
        return new self('This category is already active.', 409);
    }

    public static function classificationLocked(): self
    {
        return new self('The classification can no longer be changed because this category has financial transactions.', 409);
    }

    public static function budgetPlanClassificationLocked(): self
    {
        return new self('The classification can no longer be changed because this category is used in a budget plan.', 409);
    }

    public function errorCode(): string
    {
        return match ($this->getMessage()) {
            'System default categories are read-only.' => 'category_system_read_only',
            'This category is already archived.' => 'category_already_archived',
            'This category is already active.' => 'category_already_active',
            'The classification can no longer be changed because this category is used in a budget plan.' => 'category_budget_plan_locked',
            default => 'category_classification_locked',
        };
    }
}
