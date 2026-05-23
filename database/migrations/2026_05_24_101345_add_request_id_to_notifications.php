<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_idempotency_key_unique');

        Schema::table('notifications', function (Blueprint $table) {
            $table->string('request_id', 36)->nullable()->after('idempotency_key');
            $table->index('request_id', 'notifications_request_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_request_id_idx');
            $table->dropColumn('request_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX notifications_idempotency_key_unique ON notifications (idempotency_key) WHERE idempotency_key IS NOT NULL'
        );
    }
};
