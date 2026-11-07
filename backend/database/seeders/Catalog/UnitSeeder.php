<?php

namespace Database\Seeders\Catalog;

use App\Models\Catalog\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['Piece', 'pc', false], ['Kilogram', 'kg', true], ['Gram', 'g', true], ['Litre', 'ltr', true],
            ['Metre', 'm', true], ['Box', 'box', false], ['Carton', 'ctn', false], ['Bottle', 'btl', false],
            ['Packet', 'pkt', false], ['Bag', 'bag', false], ['Dozen', 'dz', false], ['Crate', 'crt', false],
            ['Tablet', 'tab', false], ['Strip', 'strip', false], ['Roll', 'roll', false], ['Tin', 'tin', false],
        ];
        foreach ($units as [$name, $short, $decimal]) {
            Unit::withTrashed()->firstOrCreate(['short_name' => $short], ['name' => $name, 'allow_decimal' => $decimal, 'is_active' => true]);
        }

    }
}
