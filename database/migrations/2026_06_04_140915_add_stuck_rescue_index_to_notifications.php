<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Частичный индекс покрывает только строки, которые rescue-sweep проверяет на каждом запуске:
        // уведомления в статусе queued с ненулевым счётчиком попыток.
        DB::statement(
            'CREATE INDEX notifications_stuck_rescue_idx ON notifications (updated_at) WHERE status = \'queued\' AND attempts > 0'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_stuck_rescue_idx');
    }
};
