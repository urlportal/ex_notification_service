<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subscriber_id');
            $table->uuid('bulk_request_id')->nullable();
            $table->string('channel');
            $table->string('delivery_profile');
            $table->string('status');
            $table->text('message');
            $table->string('idempotency_key', 64)->nullable();
            $table->string('provider_message_id', 120)->nullable();
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('dropped_at')->nullable();
            $table->timestampsTz();

            $table->foreign('subscriber_id', 'notifications_subscriber_id_foreign')
                ->references('id')
                ->on('subscribers')
                ->cascadeOnDelete();

            $table->index('subscriber_id', 'notifications_subscriber_id_idx');
            $table->index('status', 'notifications_status_idx');
            $table->index('bulk_request_id', 'notifications_bulk_request_id_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX notifications_idempotency_key_unique ON notifications (idempotency_key) WHERE idempotency_key IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
