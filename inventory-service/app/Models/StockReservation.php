<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * StockReservation model.
 *
 * Tracks stock locks created during Saga execution.
 * Released by compensating transactions when a Saga fails.
 *
 * @property int    $id
 * @property int    $product_id
 * @property int    $order_id      (cross-service reference to Order Service)
 * @property string $saga_id       UUID of the owning Saga
 * @property int    $quantity
 * @property string $status        active|released|fulfilled
 */
class StockReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'order_id',
        'saga_id',
        'quantity',
        'status',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
