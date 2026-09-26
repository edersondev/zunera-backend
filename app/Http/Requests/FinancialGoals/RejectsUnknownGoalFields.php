<?php

declare(strict_types=1);

namespace App\Http\Requests\FinancialGoals;

use Illuminate\Validation\Validator;

trait RejectsUnknownGoalFields
{
    /** @param list<string> $allowed */
    private function rejectUnknownFields(Validator $validator, array $allowed): void
    {
        foreach (array_keys($this->all()) as $field) {
            if (! in_array($field, $allowed, true)) {
                $validator->errors()->add((string) $field, 'The field is not supported.');
            }
        }
    }
}
