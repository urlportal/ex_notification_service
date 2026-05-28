<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('msg_inbox', function (Blueprint $table) {
            $table->uuid('message_id');
            $table->string('consumer_name', 80);
            $table->timestampTz('processed_at')->default(DB::raw('now()'));

            $table->primary(['message_id', 'consumer_name']);
        });
        DB::statement('CREATE INDEX msg_inbox_processed_at_idx ON msg_inbox (processed_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('msg_inbox');
    }
};
