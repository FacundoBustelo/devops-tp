<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorite_gifs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $id = $table->string('gif_id', 128);
            if (DB::getDriverName() === 'mysql') {
                $id->collation('utf8mb4_0900_bin');
            }
            $table->string('alias', 100);
            $table->timestamps();
            $table->unique(['user_id', 'gif_id'], 'favorite_gifs_user_gif_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorite_gifs');
    }
};
