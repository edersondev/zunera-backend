<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_card_purchases', function (Blueprint $table): void {
            $table->unsignedBigInteger('recurring_card_occurrence_id')->nullable();

            $table->foreign('recurring_card_occurrence_id', 'credit_card_purchases_occurrence_foreign')
                ->references('id')
                ->on('recurring_card_occurrences')
                ->restrictOnDelete();
            $table->unique('recurring_card_occurrence_id', 'credit_card_purchases_occurrence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('credit_card_purchases', function (Blueprint $table): void {
            $table->dropUnique('credit_card_purchases_occurrence_unique');
            $table->dropForeign('credit_card_purchases_occurrence_foreign');
            $table->dropColumn('recurring_card_occurrence_id');
        });
    }
};
