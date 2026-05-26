<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nawasara_db_servers', function (Blueprint $table) {
            // Last observed `Aborted_connects` global counter. Used by
            // AlertEvaluator to compute per-tick delta — alerting on the
            // absolute counter wouldn't make sense because it's cumulative
            // since MySQL last restarted.
            $table->unsignedBigInteger('last_aborted_connects')->default(0)->after('database_count');
        });
    }

    public function down(): void
    {
        Schema::table('nawasara_db_servers', function (Blueprint $table) {
            $table->dropColumn('last_aborted_connects');
        });
    }
};
