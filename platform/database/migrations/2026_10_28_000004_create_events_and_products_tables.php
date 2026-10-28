<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('event_id', 64);
            $table->foreignId('visitor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->unsignedBigInteger('wc_product_id')->nullable();
            $table->timestamp('occurred_at');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            // Idempotenza: il plugin puo' reinviare lo stesso batch.
            $table->unique(['site_id', 'event_id']);
            $table->index(['site_id', 'customer_id', 'occurred_at']);
            $table->index(['site_id', 'visitor_id']);
            $table->index(['site_id', 'type', 'occurred_at']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('wc_product_id');
            $table->string('sku')->nullable();
            $table->string('name', 500);
            $table->string('type', 30)->nullable();
            $table->string('status', 20)->default('publish');
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('regular_price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('stock_status', 20)->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->json('categories')->nullable();
            $table->json('tags')->nullable();
            $table->json('attributes')->nullable();
            $table->string('brand')->nullable();
            $table->text('image_url')->nullable();
            $table->text('url')->nullable();
            $table->text('short_description')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('wc_updated_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'wc_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('events');
    }
};
