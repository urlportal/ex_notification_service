<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 255)->nullable();
            $table->timestamps();

            $table->unique('phone', 'subscribers_phone_unique');
            $table->unique('email', 'subscribers_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};
