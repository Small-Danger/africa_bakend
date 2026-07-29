<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\SearchNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosProductController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $results = [];

        $variants = ProductVariant::query()
            ->with(['product.category'])
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->where(function (Builder $outer) use ($query) {
                $outer->where('barcode', $query)
                    ->orWhere(function (Builder $textSearch) use ($query) {
                        SearchNormalizer::applyTokenSearch($textSearch, $query, function (Builder $tokenQuery, string $token) {
                            SearchNormalizer::applyLike($tokenQuery, 'sku', $token);
                            SearchNormalizer::applyLike($tokenQuery, 'name', $token, 'or');
                            $tokenQuery->orWhereHas('product', function (Builder $productQuery) use ($token) {
                                SearchNormalizer::applyLike($productQuery, 'name', $token);
                            });
                        });
                    });
            })
            ->limit(20)
            ->get();

        foreach ($variants as $variant) {
            $results[] = $this->formatVariantResult($variant);
        }

        if ($variants->isEmpty() || !$variants->contains(fn ($v) => $v->barcode === $query)) {
            $productsWithoutVariants = Product::query()
                ->with('category')
                ->where('is_active', true)
                ->whereDoesntHave('variants')
                ->where(function (Builder $productQuery) use ($query) {
                    SearchNormalizer::applyTokenSearch($productQuery, $query, function (Builder $tokenQuery, string $token) {
                        SearchNormalizer::applyLike($tokenQuery, 'name', $token);
                        SearchNormalizer::applyLike($tokenQuery, 'slug', $token, 'or');
                    });
                })
                ->limit(10)
                ->get();

            foreach ($productsWithoutVariants as $product) {
                $results[] = $this->formatProductResult($product);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }

    private function formatVariantResult(ProductVariant $variant): array
    {
        $product = $variant->product;

        return [
            'type' => 'variant',
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'name' => $product->name,
            'variant_name' => $variant->name,
            'display_name' => $product->name . ' — ' . $variant->name,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'price' => $variant->price,
            'stock_quantity' => $variant->stock_quantity,
            'category' => $product->category?->name,
            'image' => $product->image_main,
        ];
    }

    private function formatProductResult(Product $product): array
    {
        return [
            'type' => 'product',
            'product_id' => $product->id,
            'product_variant_id' => null,
            'name' => $product->name,
            'variant_name' => null,
            'display_name' => $product->name,
            'sku' => null,
            'barcode' => null,
            'price' => $product->base_price,
            'stock_quantity' => null,
            'category' => $product->category?->name,
            'image' => $product->image_main,
        ];
    }
}
