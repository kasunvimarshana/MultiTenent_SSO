<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->string('customer_email');
            $table->string('customer_name');
            $table->enum('status', ['pending', 'confirmed', 'failed', 'cancelled'])
                  ->default('pending');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->uuid('saga_id')->nullable()->index();
            $table->json('saga_log')->nullable();
            $table->timestamps();

            $table->index('customer_email');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
