<?php

declare(strict_types=1);

namespace App\Exceptions\FinancialGoals;

use RuntimeException;

final class FinancialGoalStateException extends RuntimeException
{
    private function __construct(private readonly string $stateCode, string $message)
    {
        parent::__construct($message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($code, $message);
    }

    public function errorCode(): string
    {
        return $this->stateCode;
    }
}
