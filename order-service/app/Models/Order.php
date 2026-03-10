<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Order model.
 *
 * @property int    $id
 * @property string $order_number
 * @property string $customer_email
 * @property string $customer_name
 * @property string $status   pending|confirmed|failed|cancelled
 * @property float  $total_amount
 * @property string|null $saga_id
 * @property array|null  $saga_log
 */
class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'customer_email',
        'customer_name',
        'status',
        'total_amount',
        'saga_id',
        'saga_log',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'saga_log'     => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    public function appendSagaLog(string $step, string $result, array $context = []): void
    {
        $log   = $this->saga_log ?? [];
        $log[] = [
            'step'      => $step,
            'result'    => $result,
            'timestamp' => now()->toIso8601String(),
            'context'   => $context,
        ];
        $this->saga_log = $log;
        $this->save();
    }
}
