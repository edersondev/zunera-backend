<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('institution_name', 120);
            $table->string('last_four', 4)->nullable();
            $table->string('color', 32)->nullable();
            $table->string('icon', 32)->nullable();
            $table->unsignedBigInteger('credit_limit_centavos');
            $table->unsignedTinyInteger('closing_day');
            $table->unsignedTinyInteger('due_day');
            $table->string('status', 16)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'id'], 'credit_cards_owner_status_index');
            $table->index(['user_id', 'archived_at'], 'credit_cards_owner_archived_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_cards');
    }
};
