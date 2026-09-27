<?php

declare(strict_types=1);

namespace App\Http\Requests\FinancialReports;

use App\Data\FinancialReports\ReportScope;
use App\Models\Category;
use App\Models\FinancialAccount;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReportContributionRequest extends ReportScopeRequest
{
    public const array METRICS = [
        'realized_income', 'realized_expenses', 'financial_result',
        'income_category', 'expense_category',
        'account_income', 'account_expenses', 'account_net_flow',
        'account_transfer_in', 'account_transfer_out', 'account_card_settlement',
    ];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'metric' => ['required', 'string', Rule::in(self::METRICS)],
            'metric_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'which_period' => ['sometimes', 'required', Rule::in(['current', 'previous'])],
            'cursor' => ['sometimes', 'required', 'string', 'max:512'],
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return list<string> */
    protected function allowedQueryKeys(): array
    {
        return [...parent::allowedQueryKeys(), 'metric', 'metric_id', 'which_period', 'cursor', 'limit'];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->any()) {
                return;
            }

            $metric = $this->input('metric');
            $targeted = is_string($metric) && (str_ends_with($metric, '_category') || str_starts_with($metric, 'account_'));
            if ($targeted && ! $this->filled('metric_id')) {
                $validator->errors()->add('metric_id', 'Metric target is required.');
            }
            if (! $targeted && $this->has('metric_id')) {
                $validator->errors()->add('metric_id', 'Summary metrics do not accept a target.');
            }
        }];
    }

    public function toScope(): ReportScope
    {
        $scope = parent::toScope();
        $metric = (string) $this->validated('metric');
        $targetId = $this->validated('metric_id');

        if ($targetId !== null && str_starts_with($metric, 'account_') && ! FinancialAccount::query()
            ->where('user_id', $scope->userId)->whereKey((int) $targetId)->exists()) {
            throw new NotFoundHttpException('Metric target not found or not accessible.');
        }
        if ($targetId !== null && str_ends_with($metric, '_category') && ! Category::query()
            ->whereKey((int) $targetId)
            ->where(function ($query) use ($scope): void {
                $query->where('origin', 'system')
                    ->orWhere(function ($personal) use ($scope): void {
                        $personal->where('origin', 'personal')->where('user_id', $scope->userId);
                    });
            })->exists()) {
            throw new NotFoundHttpException('Metric target not found or not accessible.');
        }

        return $scope;
    }
}
