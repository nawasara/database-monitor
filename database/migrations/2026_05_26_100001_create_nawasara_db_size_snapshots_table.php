<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_db_size_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('database_id')
                ->constrained('nawasara_db_databases')
                ->cascadeOnDelete();

            // Captured size at this point in time. Data + index split so
            // operators can see whether bloat lives in table data or in
            // unused index space — common tuning question.
            $table->unsignedBigInteger('data_size_bytes');
            $table->unsignedBigInteger('index_size_bytes');
            $table->unsignedInteger('table_count');
            $table->unsignedBigInteger('row_estimate');

            $table->timestamp('captured_at')->useCurrent();

            // No updated_at — snapshots are immutable.
            $table->timestamp('created_at')->useCurrent();

            // Composite index drives both "trend for database X" and
            // "house-keeping older than N days" queries.
            $table->index(['database_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_db_size_snapshots');
    }
};
