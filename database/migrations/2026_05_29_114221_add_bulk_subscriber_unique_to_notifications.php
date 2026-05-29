<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX notifications_bulk_request_id_subscriber_id_unique '
            .'ON notifications (bulk_request_id, subscriber_id)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_bulk_request_id_subscriber_id_unique');
    }
};
