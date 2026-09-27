<?php

declare(strict_types=1);

namespace App\Http\Resources\FinancialReports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Formats one page and its signed, source-linked metric contributions. */
final class FinancialReportContributionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->resource;
        $data['contributions'] = array_map(static function (object $row): array {
            $category = ($row->category_id ?? null) === null ? null : [
                'id' => (int) $row->category_id,
                'name' => (string) $row->category_name,
                'classification' => (string) $row->category_classification,
                'status' => (string) $row->category_status,
            ];
            $account = ($row->financial_account_id ?? null) === null ? null : [
                'id' => (int) $row->financial_account_id,
                'name' => (string) $row->account_name,
                'type' => (string) $row->account_type,
                'status' => (string) $row->account_status,
            ];

            return [
                'source_kind' => (string) $row->source_kind,
                'source_id' => (int) $row->source_id,
                'recognized_date' => substr((string) $row->recognized_date, 0, 10),
                'classification' => (string) $row->classification,
                'signed_amount' => ['amount_centavos' => (int) $row->metric_amount_centavos, 'currency_code' => 'BRL'],
                'description' => (string) ($row->description ?? match ($row->source_kind) {
                    'card_statement_payment' => 'Card statement payment',
                    'transfer' => 'Transfer',
                    default => 'Financial contribution',
                }),
                'category' => $category,
                'account' => $account,
                'related_purchase_id' => isset($row->related_purchase_id) ? (int) $row->related_purchase_id : null,
                'related_statement_id' => isset($row->related_statement_id) ? (int) $row->related_statement_id : null,
                'related_installment_id' => isset($row->related_installment_id) ? (int) $row->related_installment_id : null,
                'related_credit_event_id' => isset($row->related_credit_event_id) ? (int) $row->related_credit_event_id : null,
            ];
        }, $data['contributions']);

        return $data;
    }
}
