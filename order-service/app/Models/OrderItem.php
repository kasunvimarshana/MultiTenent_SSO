<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single line-item inside an order.
 *
 * @property int    $id
 * @property int    $order_id
 * @property int    $product_id  (FK lives in Inventory Service)
 * @property string $product_name
 * @property int    $quantity
 * @property float  $unit_price
 * @property float  $subtotal
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'subtotal'   => 'float',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
