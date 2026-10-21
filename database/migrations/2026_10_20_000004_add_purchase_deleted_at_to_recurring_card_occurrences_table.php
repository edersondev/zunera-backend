<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_card_occurrences', function (Blueprint $table): void {
            $table->timestamp('purchase_deleted_at')->nullable()->after('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_card_occurrences', function (Blueprint $table): void {
            $table->dropColumn('purchase_deleted_at');
        });
    }
};
