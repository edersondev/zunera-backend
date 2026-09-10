<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin', 16);
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->string('classification', 16);
            $table->string('color', 24);
            $table->string('icon', 32);
            $table->string('status', 16)->default('active');
            $table->string('active_personal_normalized_name', 120)->nullable()->storedAs("CASE WHEN origin = 'personal' AND status = 'active' THEN normalized_name ELSE NULL END");
            $table->unique(['user_id', 'classification', 'active_personal_normalized_name'], 'categories_personal_active_name_unique');
            $table->timestamp('archived_at')->nullable();
            $table->boolean('has_financial_transactions')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
