<?php

use App\Http\Controllers\OrderController;
use App\Http\Controllers\SagaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Order Service API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api.
|
| Orders:
|   GET    /api/orders                  List all orders (filtering, pagination)
|   POST   /api/orders                  Create an order (basic, no Saga)
|   GET    /api/orders/{id}             Get a single order
|   PUT    /api/orders/{id}             Update an order
|   DELETE /api/orders/{id}             Delete an order
|   GET    /api/orders/{id}/items       Get items for an order
|
| Saga:
|   POST   /api/saga/place-order        Place order via Saga orchestration
|   POST   /api/saga/{sagaId}/rollback  Manually trigger rollback (demo)
|   GET    /api/saga/{sagaId}/status    Check saga execution status
|
*/

// ── Orders ────────────────────────────────────────────────────────────────
Route::apiResource('orders', OrderController::class);
Route::get('orders/{id}/items', [OrderController::class, 'items']);

// ── Saga / Distributed Transactions ───────────────────────────────────────
Route::prefix('saga')->group(function () {
    Route::post('place-order',            [SagaController::class, 'placeOrder']);
    Route::post('{sagaId}/rollback',      [SagaController::class, 'rollback']);
    Route::get('{sagaId}/status',         [SagaController::class, 'status']);
});
