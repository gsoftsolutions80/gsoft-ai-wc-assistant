<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_identities', function (Blueprint $table) {
            $table->id();
            $table->char('email_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('wc_user_id')->nullable();
            $table->char('email_hash', 64)->nullable();
            $table->boolean('consent_tracking')->default(false);
            $table->boolean('consent_cross_site')->default(false);
            $table->foreignId('global_identity_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('merged_into_id')->nullable()->index();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'wc_user_id']);
            $table->index(['site_id', 'email_hash']);
        });

        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->uuid('anon_id');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'anon_id']);
        });

        Schema::create('identity_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20); // login | checkout | newsletter | cookie
            $table->unsignedTinyInteger('confidence')->default(100);
            $table->timestamps();

            $table->unique(['visitor_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_links');
        Schema::dropIfExists('visitors');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('global_identities');
    }
};
