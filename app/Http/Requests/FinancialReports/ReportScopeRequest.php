<?php

declare(strict_types=1);

namespace App\Http\Requests\FinancialReports;

use App\Data\FinancialReports\ReportScope;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Services\FinancialReports\ReportPeriodResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ReportScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preset' => ['sometimes', 'required', 'string', Rule::in([
                'current_month', 'previous_month', 'historical_month', 'current_year', 'previous_year', 'custom',
            ])],
            'month' => ['sometimes', 'required', 'string', 'regex:/^[0-9]{4}-(0[1-9]|1[0-2])$/'],
            'from' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
            'to' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
            'account_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'category_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'transaction_type' => ['sometimes', 'required', Rule::in(['income', 'expense'])],
        ];
    }

    /** @return list<string> */
    protected function allowedQueryKeys(): array
    {
        return ['preset', 'month', 'from', 'to', 'account_id', 'category_id', 'transaction_type'];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $unknown = array_diff(array_keys($this->query()), $this->allowedQueryKeys());
            if ($unknown !== []) {
                $validator->errors()->add('query', 'Unsupported report filter: '.implode(', ', $unknown).'.');
            }

            if ($validator->errors()->any()) {
                return;
            }

            try {
                app(ReportPeriodResolver::class)->resolve((int) $this->user()->id, $this->validated());
            } catch (Throwable $error) {
                $validator->errors()->add('preset', $error->getMessage());
            }
        }];
    }

    public function toScope(): ReportScope
    {
        $data = $this->validated();
        $userId = (int) $this->user()->id;

        if (isset($data['account_id']) && ! FinancialAccount::query()
            ->where('user_id', $userId)->whereKey((int) $data['account_id'])->exists()) {
            throw new NotFoundHttpException('Account not found or not accessible.');
        }

        if (isset($data['category_id']) && ! Category::query()
            ->whereKey((int) $data['category_id'])
            ->where(function ($query) use ($userId): void {
                $query->where('origin', 'system')
                    ->orWhere(function ($personal) use ($userId): void {
                        $personal->where('origin', 'personal')->where('user_id', $userId);
                    });
            })->exists()) {
            throw new NotFoundHttpException('Category not found or not accessible.');
        }

        return app(ReportPeriodResolver::class)->resolve($userId, $data);
    }
}
