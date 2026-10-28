<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToSite;

    protected $fillable = [
        'site_id', 'wc_product_id', 'sku', 'name', 'type', 'status', 'price', 'regular_price', 'sale_price',
        'currency', 'stock_status', 'stock_quantity', 'categories', 'tags', 'attributes', 'brand', 'image_url',
        'url', 'short_description', 'description', 'wc_updated_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'regular_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'categories' => 'array',
            'tags' => 'array',
            'attributes' => 'array',
            'wc_updated_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
