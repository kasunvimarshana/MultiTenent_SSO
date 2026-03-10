<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['sku' => 'WGT-A001', 'name' => 'Widget A',    'category' => 'widgets',  'price' => 29.99, 'stock_quantity' => 100],
            ['sku' => 'WGT-B002', 'name' => 'Widget B',    'category' => 'widgets',  'price' => 49.99, 'stock_quantity' => 50],
            ['sku' => 'GDG-C003', 'name' => 'Gadget C',    'category' => 'gadgets',  'price' => 99.00, 'stock_quantity' => 30],
            ['sku' => 'GDG-D004', 'name' => 'Gadget D',    'category' => 'gadgets',  'price' => 149.00,'stock_quantity' => 20],
            ['sku' => 'DEV-E005', 'name' => 'Device E',    'category' => 'devices',  'price' => 299.00,'stock_quantity' => 10],
            ['sku' => 'ACC-F006', 'name' => 'Accessory F', 'category' => 'accessories','price'=> 9.99, 'stock_quantity' => 200],
        ];

        foreach ($products as $data) {
            Product::firstOrCreate(['sku' => $data['sku']], array_merge($data, [
                'description'       => "Description for {$data['name']}",
                'reserved_quantity' => 0,
                'active'            => true,
            ]));
        }
    }
}
