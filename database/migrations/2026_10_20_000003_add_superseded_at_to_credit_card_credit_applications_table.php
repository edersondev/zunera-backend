<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_card_credit_applications', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable();
            $table->index(['credit_card_id', 'superseded_at', 'credit_card_statement_id'], 'cc_credit_apps_card_active_statement_idx');
        });
    }

    public function down(): void
    {
        Schema::table('credit_card_credit_applications', function (Blueprint $table): void {
            $table->dropIndex('cc_credit_apps_card_active_statement_idx');
            $table->dropColumn('superseded_at');
        });
    }
};
