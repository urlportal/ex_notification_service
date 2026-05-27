<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('msg_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('exchange', 120);
            $table->string('routing_key', 120);
            $table->jsonb('payload');
            $table->jsonb('headers')->default('{}');
            $table->string('status', 20)->default('pending');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at')->default(DB::raw('now()'));
            $table->timestampTz('published_at')->nullable();
        });
        // Составной индекс для основного SELECT: WHERE status='pending' ORDER BY created_at SKIP LOCKED.
        DB::statement('CREATE INDEX msg_outbox_status_created_at_idx ON msg_outbox (status, created_at)');
        DB::statement('CREATE INDEX msg_outbox_published_at_idx ON msg_outbox (published_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('msg_outbox');
    }
};
