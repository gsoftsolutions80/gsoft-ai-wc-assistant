<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    private const UPDATABLE = [
        'sku', 'name', 'type', 'status', 'price', 'regular_price', 'sale_price', 'currency', 'stock_status',
        'stock_quantity', 'categories', 'tags', 'attributes', 'brand', 'image_url', 'url', 'short_description',
        'description', 'wc_updated_at', 'synced_at', 'updated_at',
    ];

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'products' => ['nullable', 'array', 'max:100'],
            'products.*.wc_product_id' => ['required', 'integer', 'min:1'],
            'products.*.name' => ['required', 'string', 'max:500'],
            'products.*.sku' => ['nullable', 'string', 'max:255'],
            'products.*.type' => ['nullable', 'string', 'max:30'],
            'products.*.status' => ['nullable', 'string', 'max:20'],
            'products.*.price' => ['nullable', 'numeric'],
            'products.*.regular_price' => ['nullable', 'numeric'],
            'products.*.sale_price' => ['nullable', 'numeric'],
            'products.*.currency' => ['nullable', 'string', 'size:3'],
            'products.*.stock_status' => ['nullable', 'string', 'max:20'],
            'products.*.stock_quantity' => ['nullable', 'integer'],
            'products.*.categories' => ['nullable', 'array'],
            'products.*.tags' => ['nullable', 'array'],
            'products.*.attributes' => ['nullable', 'array'],
            'products.*.brand' => ['nullable', 'string', 'max:255'],
            'products.*.image_url' => ['nullable', 'string', 'max:2000'],
            'products.*.url' => ['nullable', 'string', 'max:2000'],
            'products.*.short_description' => ['nullable', 'string', 'max:2000'],
            'products.*.description' => ['nullable', 'string', 'max:5000'],
            'products.*.wc_updated_at' => ['nullable', 'integer', 'min:0'],
            'deleted' => ['nullable', 'array', 'max:500'],
            'deleted.*' => ['integer', 'min:1'],
        ]);

        $siteId = $request->attributes->get('site')->id;
        $now = now()->toDateTimeString();

        $rows = collect($data['products'] ?? [])->map(fn (array $p) => [
            'site_id' => $siteId,
            'wc_product_id' => $p['wc_product_id'],
            'sku' => $p['sku'] ?? null,
            'name' => $p['name'],
            'type' => $p['type'] ?? null,
            'status' => $p['status'] ?? 'publish',
            'price' => $p['price'] ?? null,
            'regular_price' => $p['regular_price'] ?? null,
            'sale_price' => $p['sale_price'] ?? null,
            'currency' => $p['currency'] ?? null,
            'stock_status' => $p['stock_status'] ?? null,
            'stock_quantity' => $p['stock_quantity'] ?? null,
            'categories' => json_encode($p['categories'] ?? [], JSON_UNESCAPED_UNICODE),
            'tags' => json_encode($p['tags'] ?? [], JSON_UNESCAPED_UNICODE),
            'attributes' => json_encode($p['attributes'] ?? [], JSON_UNESCAPED_UNICODE),
            'brand' => $p['brand'] ?? null,
            'image_url' => $p['image_url'] ?? null,
            'url' => $p['url'] ?? null,
            'short_description' => $p['short_description'] ?? null,
            'description' => $p['description'] ?? null,
            'wc_updated_at' => isset($p['wc_updated_at']) ? date('Y-m-d H:i:s', $p['wc_updated_at']) : null,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        $upserted = 0;
        if ($rows !== []) {
            Product::upsert($rows, ['site_id', 'wc_product_id'], self::UPDATABLE);
            $upserted = count($rows);
        }

        $deleted = empty($data['deleted'])
            ? 0
            : Product::where('site_id', $siteId)->whereIn('wc_product_id', $data['deleted'])->delete();

        return response()->json(['upserted' => $upserted, 'deleted' => $deleted]);
    }
}
