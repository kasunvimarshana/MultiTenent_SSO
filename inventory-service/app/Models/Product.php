<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Product model (Inventory Service – PostgreSQL).
 *
 * @property int    $id
 * @property string $sku
 * @property string $name
 * @property string $category
 * @property string $description
 * @property float  $price
 * @property int    $stock_quantity
 * @property int    $reserved_quantity
 * @property bool   $active
 */
class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'name',
        'category',
        'description',
        'price',
        'stock_quantity',
        'reserved_quantity',
        'active',
    ];

    protected $casts = [
        'price'             => 'float',
        'stock_quantity'    => 'integer',
        'reserved_quantity' => 'integer',
        'active'            => 'boolean',
    ];

    // ── Virtual attribute ─────────────────────────────────────────────────

    public function getAvailableQuantityAttribute(): int
    {
        return max(0, $this->stock_quantity - $this->reserved_quantity);
    }

    protected $appends = ['available_quantity'];

    // ── Relationships ─────────────────────────────────────────────────────

    public function reservations()
    {
        return $this->hasMany(StockReservation::class);
    }
}
