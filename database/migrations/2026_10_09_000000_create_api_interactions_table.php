<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_interactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method', 16);
            $table->string('service');
            $table->json('request_data');
            $table->unsignedSmallInteger('status');
            $table->json('response_data')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->dateTime('occurred_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_interactions');
    }
};
