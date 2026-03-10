<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Product CRUD + restocking.
 *
 * Demonstrates:
 *  - Filtering (category, price range, search, in_stock)
 *  - Relational queries (products with reservation counts)
 *  - Vertical scalability via PostgreSQL indices
 */
class ProductController extends Controller
{
    // ── List products ─────────────────────────────────────────────────────

    /**
     * GET /api/products
     *
     * Filters:
     *   category       string
     *   search         searches name and sku (ILIKE)
     *   min_price      float
     *   max_price      float
     *   in_stock       boolean (1/0)
     *   active         boolean (1/0, default 1)
     *   per_page       int (default 15)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::withCount([
            'reservations as active_reservations_count' => fn($q) => $q->where('status', 'active'),
        ]);

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('sku',  'ilike', "%{$search}%");
            });
        }
        if ($minPrice = $request->query('min_price')) {
            $query->where('price', '>=', (float) $minPrice);
        }
        if ($maxPrice = $request->query('max_price')) {
            $query->where('price', '<=', (float) $maxPrice);
        }
        if ($request->has('in_stock') && $request->boolean('in_stock')) {
            $query->whereRaw('stock_quantity > reserved_quantity');
        }
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        } else {
            $query->where('active', true);
        }

        $perPage  = (int) $request->query('per_page', 15);
        $products = $query->orderBy('name')->paginate($perPage);

        return response()->json($products);
    }

    // ── Create product ────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sku'            => 'required|string|unique:products,sku',
            'name'           => 'required|string|max:255',
            'category'       => 'required|string|max:100',
            'description'    => 'nullable|string',
            'price'          => 'required|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'active'         => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product = Product::create($validator->validated());

        return response()->json($product, 201);
    }

    // ── Get single product ────────────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $product = Product::with('reservations')->findOrFail($id);
        return response()->json($product);
    }

    // ── Update product ────────────────────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        $product   = Product::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'name'        => 'sometimes|string|max:255',
            'category'    => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'price'       => 'sometimes|numeric|min:0',
            'active'      => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product->update($validator->validated());

        return response()->json($product);
    }

    // ── Delete product ────────────────────────────────────────────────────

    public function destroy(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->delete();
        return response()->json(['message' => 'Product deleted.']);
    }

    // ── Restock product ───────────────────────────────────────────────────

    /**
     * POST /api/products/{id}/restock
     *
     * Body: { "quantity": 50 }
     */
    public function restock(Request $request, int $id): JsonResponse
    {
        $product   = Product::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product->stock_quantity += $validator->validated()['quantity'];
        $product->save();

        return response()->json([
            'message'           => 'Restocked successfully.',
            'new_stock'         => $product->stock_quantity,
            'available_quantity'=> $product->available_quantity,
        ]);
    }
}
