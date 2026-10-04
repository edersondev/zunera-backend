<?php

declare(strict_types=1);

namespace App\Services\AccountData;

use App\Exceptions\AccountData\ArchiveRestoreException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

final class ArchiveRecordRestorer
{
    private const INSERT_ORDER = [
        'categories', 'financial_accounts', 'credit_cards', 'monthly_budgets',
        'recurring_transactions', 'financial_goals', 'credit_card_statements',
        'credit_card_purchases', 'budget_category_plans', 'transactions',
        'transfers', 'recurring_card_occurrences', 'credit_card_installments',
        'credit_card_statement_payments', 'credit_card_credit_events',
        'credit_card_credit_applications', 'financial_goal_activities',
    ];

    private const REFERENCES = [
        'recurring_transactions' => [
            'financial_account_id' => 'financial_accounts', 'category_id' => 'categories',
            'credit_card_id' => 'credit_cards',
        ],
        'financial_goals' => ['financial_account_id' => 'financial_accounts'],
        'credit_card_statements' => ['credit_card_id' => 'credit_cards'],
        'credit_card_purchases' => [
            'credit_card_id' => 'credit_cards', 'category_id' => 'categories',
        ],
        'budget_category_plans' => [
            'monthly_budget_id' => 'monthly_budgets', 'category_id' => 'categories',
        ],
        'transactions' => [
            'financial_account_id' => 'financial_accounts', 'category_id' => 'categories',
            'recurring_transaction_id' => 'recurring_transactions',
        ],
        'transfers' => [
            'source_financial_account_id' => 'financial_accounts',
            'destination_financial_account_id' => 'financial_accounts',
        ],
        'recurring_card_occurrences' => [
            'recurring_transaction_id' => 'recurring_transactions',
            'category_id_original' => 'categories', 'credit_card_id_original' => 'credit_cards',
            'category_id_override' => 'categories', 'credit_card_id_override' => 'credit_cards',
        ],
        'credit_card_installments' => [
            'credit_card_purchase_id' => 'credit_card_purchases', 'credit_card_id' => 'credit_cards',
            'credit_card_statement_id' => 'credit_card_statements',
        ],
        'credit_card_statement_payments' => [
            'credit_card_statement_id' => 'credit_card_statements', 'credit_card_id' => 'credit_cards',
            'financial_account_id' => 'financial_accounts',
        ],
        'credit_card_credit_events' => [
            'credit_card_id' => 'credit_cards', 'credit_card_purchase_id' => 'credit_card_purchases',
        ],
        'credit_card_credit_applications' => [
            'credit_card_credit_event_id' => 'credit_card_credit_events',
            'credit_card_id' => 'credit_cards', 'credit_card_installment_id' => 'credit_card_installments',
            'credit_card_statement_id' => 'credit_card_statements',
        ],
        'financial_goal_activities' => ['financial_goal_id' => 'financial_goals'],
    ];

    public function verify(int $archiveId, int $expectedCount): void
    {
        $records = DB::table('financial_data_archive_records')->where('financial_data_archive_id', $archiveId);
        if ($records->count() !== $expectedCount
            || (clone $records)->whereNotIn('record_type', self::INSERT_ORDER)->exists()) {
            throw $this->unavailable();
        }
    }

    /** @return array<string, array<int, int>> */
    public function restore(int $userId, int $archiveId): array
    {
        $ids = [];
        $deferredOccurrences = [];

        foreach (self::INSERT_ORDER as $table) {
            $columns = Schema::getColumnListing($table);
            DB::table('financial_data_archive_records')
                ->where('financial_data_archive_id', $archiveId)
                ->where('record_type', $table)
                ->orderBy('id')
                ->chunkById(100, function ($records) use ($userId, $table, $columns, &$ids, &$deferredOccurrences): void {
                    foreach ($records as $record) {
                        $oldId = (int) $record->source_id;
                        $data = $this->payload((string) $record->payload, $oldId, $table, $userId);

                        if ($table === 'categories' && $data['origin'] === 'system') {
                            $currentId = DB::table('categories')->where('origin', 'system')
                                ->where('classification', $data['classification'])
                                ->where('normalized_name', $data['normalized_name'])->value('id');
                            if ($currentId === null) {
                                throw $this->unavailable();
                            }
                            $ids[$table][$oldId] = (int) $currentId;

                            continue;
                        }

                        if ($table === 'categories' && $data['origin'] !== 'personal') {
                            throw $this->unavailable();
                        }
                        if ($table !== 'budget_category_plans') {
                            $data['user_id'] = $userId;
                        }

                        unset($data['id'], $data['active_normalized_name'], $data['active_personal_normalized_name']);
                        if ($table === 'credit_card_purchases') {
                            $deferredOccurrences[$oldId] = $data['recurring_card_occurrence_id'] ?? null;
                            $data['recurring_card_occurrence_id'] = null;
                        }
                        if ($table === 'financial_goal_activities' && isset($data['financial_account_id_at_time'])) {
                            $data['financial_account_id_at_time'] = $ids['financial_accounts'][(int) $data['financial_account_id_at_time']] ?? null;
                        }

                        foreach (self::REFERENCES[$table] ?? [] as $field => $target) {
                            if (array_key_exists($field, $data) && $data[$field] !== null) {
                                $data[$field] = $this->mapped($ids, $target, $data[$field]);
                            }
                        }
                        if (array_diff(array_keys($data), $columns) !== []) {
                            throw $this->unavailable();
                        }
                        $ids[$table][$oldId] = (int) DB::table($table)->insertGetId($data);
                    }
                });
        }

        foreach ($deferredOccurrences as $oldPurchaseId => $oldOccurrenceId) {
            if ($oldOccurrenceId !== null) {
                DB::table('credit_card_purchases')->where('id', $ids['credit_card_purchases'][$oldPurchaseId])
                    ->update(['recurring_card_occurrence_id' => $this->mapped($ids, 'recurring_card_occurrences', $oldOccurrenceId)]);
            }
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function payload(string $json, int $oldId, string $table, int $userId): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->unavailable();
        }
        if (! is_array($data) || ! isset($data['id']) || ! is_int($data['id']) || $data['id'] !== $oldId) {
            throw $this->unavailable();
        }
        if ($table === 'categories') {
            if (! in_array($data['origin'] ?? null, ['personal', 'system'], true)
                || ($data['origin'] === 'personal' && ($data['user_id'] ?? null) !== $userId)
                || ($data['origin'] === 'system' && ($data['user_id'] ?? null) !== null)
                || ! is_string($data['classification'] ?? null)
                || ! is_string($data['normalized_name'] ?? null)) {
                throw $this->unavailable();
            }
        } elseif ($table !== 'budget_category_plans' && ($data['user_id'] ?? null) !== $userId) {
            throw $this->unavailable();
        }

        return $data;
    }

    /** @param array<string, array<int, int>> $ids */
    private function mapped(array $ids, string $table, mixed $oldId): int
    {
        if (! is_int($oldId) || ! isset($ids[$table][$oldId])) {
            throw $this->unavailable();
        }

        return $ids[$table][$oldId];
    }

    private function unavailable(): ArchiveRestoreException
    {
        return new ArchiveRestoreException('This archive cannot be restored safely. Current data was left unchanged.');
    }
}
