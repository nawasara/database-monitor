<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_db_databases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('server_id')
                ->constrained('nawasara_db_servers')
                ->cascadeOnDelete();

            $table->string('name');
            // 'user' | 'system' — UI default hides system per config.
            $table->string('kind', 16)->default('user');

            // Latest snapshot (Fase B fills in). Null until first metrics sync.
            $table->unsignedBigInteger('data_size_bytes')->nullable();
            $table->unsignedBigInteger('index_size_bytes')->nullable();
            $table->unsignedInteger('table_count')->nullable();
            $table->unsignedBigInteger('row_estimate')->nullable();

            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->unique(['server_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_db_databases');
    }
};
