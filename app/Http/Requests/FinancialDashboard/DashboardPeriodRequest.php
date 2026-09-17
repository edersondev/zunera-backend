<?php

declare(strict_types=1);

namespace App\Http\Requests\FinancialDashboard;

use App\Data\FinancialDashboard\DashboardPeriodData;
use App\Services\Transactions\TransactionDateRange;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DashboardPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preset' => ['nullable', Rule::in([
                DashboardPeriodData::CURRENT_MONTH,
                DashboardPeriodData::PREVIOUS_MONTH,
                DashboardPeriodData::CUSTOM,
            ])],
            'from' => ['nullable', 'string', 'max:10'],
            'to' => ['nullable', 'string', 'max:10'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->preset() !== DashboardPeriodData::CUSTOM) {
                    return;
                }

                if (! $this->filled('from')) {
                    $validator->errors()->add('from', 'Provide the custom period start date.');
                }

                if (! $this->filled('to')) {
                    $validator->errors()->add('to', 'Provide the custom period end date.');
                }
            },
        ];
    }

    public function preset(): string
    {
        $preset = $this->input('preset');

        return is_string($preset) && $preset !== '' ? $preset : DashboardPeriodData::CURRENT_MONTH;
    }

    /**
     * @throws ValidationException
     */
    public function toData(): DashboardPeriodData
    {
        $preset = $this->preset();

        if ($preset !== DashboardPeriodData::CUSTOM) {
            return DashboardPeriodData::fromPreset($preset);
        }

        $from = TransactionDateRange::normalize((string) $this->input('from'), 'from');
        $to = TransactionDateRange::normalize((string) $this->input('to'), 'to');

        if ($from > $to) {
            throw ValidationException::withMessages([
                'to' => ['The custom period end date must be on or after its start date.'],
            ]);
        }

        return DashboardPeriodData::custom($from, $to);
    }
}
