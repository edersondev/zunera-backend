<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('recurring_transaction_id')->nullable();
            $table->date('recurrence_scheduled_date')->nullable();

            $table->foreign('recurring_transaction_id', 'transactions_recurrence_foreign')
                ->references('id')
                ->on('recurring_transactions')
                ->nullOnDelete();
            $table->unique(['recurring_transaction_id', 'recurrence_scheduled_date'], 'transactions_recurrence_occurrence_unique');
            $table->index(['recurring_transaction_id', 'recurrence_scheduled_date'], 'transactions_recurrence_listing_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique('transactions_recurrence_occurrence_unique');
            $table->dropIndex('transactions_recurrence_listing_index');
            $table->dropForeign('transactions_recurrence_foreign');
            $table->dropColumn(['recurring_transaction_id', 'recurrence_scheduled_date']);
        });
    }
};
