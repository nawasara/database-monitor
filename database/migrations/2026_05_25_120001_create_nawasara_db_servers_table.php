<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_db_servers', function (Blueprint $table) {
            $table->id();

            // Stable slug for cross-reference (e.g. alert keys, audit logs).
            // For the current 1-server deployment a single row with slug
            // 'kominfo-central' is enough — schema stays multi-server capable.
            $table->string('slug', 64)->unique();
            $table->string('label');

            // Captured at last sync — null until the first successful probe.
            $table->string('version')->nullable();
            $table->string('hostname')->nullable();
            $table->string('os')->nullable();
            $table->string('datadir')->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->unsignedInteger('max_connections')->nullable();
            $table->unsignedInteger('database_count')->nullable();

            // 'online' | 'unreachable' | 'degraded' | 'unknown'
            $table->string('status', 16)->default('unknown');
            $table->string('status_message')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_db_servers');
    }
};
