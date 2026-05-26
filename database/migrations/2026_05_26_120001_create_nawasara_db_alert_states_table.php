<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_db_alert_states', function (Blueprint $table) {
            $table->id();

            // (server_id, rule_key) is the natural identity. One row per
            // active alert condition tracked. Server FK so cascading server
            // deletion cleans up its state too.
            $table->foreignId('server_id')
                ->constrained('nawasara_db_servers')
                ->cascadeOnDelete();

            // e.g. 'server.unreachable', 'connections.high', 'aborted.high'
            $table->string('rule_key', 64);

            // Current evaluation: 'ok' (rule not triggering) vs 'firing'
            // (rule triggering). Used both for state-change detection and
            // for the "show me what's wrong right now" view in the UI.
            $table->string('state', 16)->default('ok');

            // Human-readable context — captured at the moment alert fired
            // so the notification email + history both can show it.
            $table->text('message')->nullable();

            // Anti-spam: when did we last actually send a notification
            // for this rule. Compared against config('alerts.cooldown_minutes').
            $table->timestamp('last_notified_at')->nullable();

            // When the rule first started firing in the current spell.
            // Resets to null when state goes back to ok.
            $table->timestamp('firing_since')->nullable();

            $table->timestamps();

            $table->unique(['server_id', 'rule_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_db_alert_states');
    }
};
