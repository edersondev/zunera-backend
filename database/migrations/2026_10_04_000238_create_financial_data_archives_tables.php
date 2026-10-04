<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_data_archives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('record_count')->default(0);
            $table->timestamp('created_at');
            $table->index(['user_id', 'created_at', 'id']);
        });

        Schema::create('financial_data_archive_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('financial_data_archive_id')->constrained('financial_data_archives')->cascadeOnDelete();
            $table->string('record_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->json('payload');
            $table->unique(['financial_data_archive_id', 'record_type', 'source_id'], 'financial_archive_record_unique');
            $table->index(['financial_data_archive_id', 'record_type', 'source_id'], 'financial_archive_records_list_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_data_archive_records');
        Schema::dropIfExists('financial_data_archives');
    }
};
