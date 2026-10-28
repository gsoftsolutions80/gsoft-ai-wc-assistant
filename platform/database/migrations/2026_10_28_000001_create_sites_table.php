<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('domain')->index();
            $table->string('site_key', 64)->unique();
            $table->text('site_secret'); // cifrato (cast "encrypted"): serve in chiaro per calcolare l'HMAC
            $table->string('status', 20)->default('active');
            $table->string('plan', 30)->default('free');
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
