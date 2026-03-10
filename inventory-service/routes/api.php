<?php

use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory Service API Routes
|--------------------------------------------------------------------------
|
| Products:
|   GET    /api/products               List products (filter by category, price, search, in_stock)
|   POST   /api/products               Create product
|   GET    /api/products/{id}          Get product with stock level
|   PUT    /api/products/{id}          Update product
|   DELETE /api/products/{id}          Delete product
|   POST   /api/products/{id}/restock  Restock a product
|
| Reservations (Saga compensating support):
|   POST   /api/reservations           Reserve stock for an order
|   DELETE /api/reservations/release   Release (rollback) reservations
|   GET    /api/reservations           List reservations
|
*/

Route::apiResource('products', ProductController::class);
Route::post('products/{id}/restock', [ProductController::class, 'restock']);

Route::post('reservations',          [ReservationController::class, 'store']);
Route::delete('reservations/release',[ReservationController::class, 'release']);
Route::get('reservations',           [ReservationController::class, 'index']);
