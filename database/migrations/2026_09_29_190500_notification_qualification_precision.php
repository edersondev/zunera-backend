<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preference_changes', function (Blueprint $table): void {
            $table->timestamp('effective_at', 6)->change();
        });
        Schema::table('notification_projection_facts', function (Blueprint $table): void {
            $table->timestamp('qualified_at', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('notification_projection_facts', function (Blueprint $table): void {
            $table->timestamp('qualified_at')->nullable()->change();
        });
        Schema::table('notification_preference_changes', function (Blueprint $table): void {
            $table->timestamp('effective_at')->change();
        });
    }
};
