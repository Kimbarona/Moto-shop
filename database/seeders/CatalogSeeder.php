<?php

namespace Database\Seeders;

use App\Enums\CommissionType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\MotorcycleModel;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Sample parts with fitments, the labor catalog and the "PMS – Scooter" package.
 * Products start at zero stock; opening stock is posted through StockService.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $scooters125 = ['Click 125', 'Beat', 'Mio i 125'];
        $hondaScooters = ['Click 125', 'Click 160', 'ADV 160'];
        $yamahaMaxi = ['NMAX', 'Aerox'];

        // name, part no., brand, category, unit, avg cost, SRP, reorder level, universal, fits
        $parts = [
            ['Engine Oil 10W-40 (Bulk)', 'OIL-BULK-1040', 'Shell', 'Oil & Lubricants', 'L', 255, 380, 5, true, []],
            ['Yamalube 4T 10W-40 0.8L', '90793-AT415', 'Yamalube', 'Oil & Lubricants', 'pc', 215, 290, 6, true, []],
            ['Shell Advance AX7 Scooter 10W-40 0.8L', 'AX7-SC-08', 'Shell', 'Oil & Lubricants', 'pc', 230, 320, 6, true, []],
            ['Motul 3100 Gold 4T 10W-40 1L', 'MTL-3100-1L', 'Motul', 'Oil & Lubricants', 'pc', 330, 450, 4, true, []],
            ['Gear Oil 80W-90 120ml', 'GO-8090-120', 'Yamalube', 'Oil & Lubricants', 'pc', 55, 85, 6, true, []],
            ['Chain Lube Spray 400ml', 'CL-400', 'Motul', 'Oil & Lubricants', 'pc', 260, 360, 3, true, []],
            ['Oil Filter – Honda Click / ADV', '15412-K0R-V01', 'Honda Genuine', 'Filters', 'pc', 120, 180, 5, false, $hondaScooters],
            ['Oil Filter – Yamaha NMAX / Aerox', '5YP-E3440-00', 'Yamaha Genuine', 'Filters', 'pc', 135, 195, 5, false, $yamahaMaxi],
            ['Air Filter – Honda Click 125i', '17210-K59-A10', 'Honda Genuine', 'Filters', 'pc', 290, 420, 3, false, ['Click 125']],
            ['Air Filter – Yamaha NMAX v2', 'B6H-E4451-00', 'Yamaha Genuine', 'Filters', 'pc', 310, 450, 3, false, ['NMAX']],
            ['Air Filter – Mio i 125', '2PH-E4451-00', 'Yamaha Genuine', 'Filters', 'pc', 240, 350, 3, false, ['Mio i 125']],
            ['Brake Pad Front – Click / Beat', 'BP-HND-SC', 'Bendix', 'Brakes', 'set', 180, 280, 4, false, ['Click 125', 'Click 160', 'Beat']],
            ['Brake Pad Front – NMAX / Aerox', 'BP-YMH-MAXI', 'Bendix', 'Brakes', 'set', 210, 320, 4, false, $yamahaMaxi],
            ['Brake Shoe Rear – Click / Beat / Mio', 'BS-SC-REAR', 'Bendix', 'Brakes', 'set', 150, 240, 4, false, $scooters125],
            ['Brake Fluid DOT 4 250ml', 'PRS-DOT4-250', 'Prestone', 'Brakes', 'pc', 95, 150, 4, true, []],
            ['Drive Belt – Honda Click 125', '23100-K35-V01', 'Bando', 'Drive / CVT / Chain', 'pc', 780, 1100, 2, false, ['Click 125']],
            ['Drive Belt – Yamaha NMAX / Aerox', '2DP-E7641-00', 'Bando', 'Drive / CVT / Chain', 'pc', 950, 1350, 2, false, $yamahaMaxi],
            ['Flyball Roller Set – Mio i 125', 'FR-MIO-12G', 'RCB', 'Drive / CVT / Chain', 'set', 160, 250, 3, false, ['Mio i 125']],
            ['Chain & Sprocket Set 428 – XRM 125', 'CS-428-XRM', 'RK', 'Drive / CVT / Chain', 'set', 720, 1050, 2, false, ['XRM 125']],
            ['Chain & Sprocket Set 428 – Raider R150', 'CS-428-R150', 'RK', 'Drive / CVT / Chain', 'set', 860, 1250, 2, false, ['Raider R150']],
            ['Spark Plug NGK CPR8EA-9', 'CPR8EA-9', 'NGK', 'Electrical', 'pc', 95, 150, 10, false, ['Click 125', 'Beat', 'XRM 125', 'TMX 155', 'Smash 115', 'Mio i 125']],
            ['Spark Plug NGK MR8K-9', 'MR8K-9', 'NGK', 'Electrical', 'pc', 210, 320, 6, false, ['NMAX', 'Aerox', 'Click 160', 'ADV 160']],
            ['Battery Motolite YTZ5S (MF)', 'MTL-YTZ5S', 'Motolite', 'Electrical', 'pc', 1050, 1450, 2, false, ['Click 125', 'Beat', 'Mio i 125', 'Smash 115']],
            ['Battery Motolite YTZ7V (MF)', 'MTL-YTZ7V', 'Motolite', 'Electrical', 'pc', 1350, 1850, 2, false, ['NMAX', 'Aerox', 'Click 160', 'ADV 160']],
            ['Headlight Bulb H4 12V 35/35W', 'OSR-H4-3535', 'Osram', 'Electrical', 'pc', 110, 180, 5, true, []],
            ['Signal Light Bulb 12V 10W', 'OSR-S10W', 'Osram', 'Electrical', 'pair', 30, 60, 10, true, []],
            ['Tire 80/90-14 Tubeless', 'IRC-8090-14', 'IRC', 'Tires & Wheels', 'pc', 980, 1350, 2, false, $scooters125],
            ['Tire 90/90-14 Tubeless', 'IRC-9090-14', 'IRC', 'Tires & Wheels', 'pc', 1150, 1550, 2, false, ['Click 125', 'Click 160', 'Mio i 125', 'Beat']],
            ['Tire 110/70-13 Angel Scooter', 'PIR-110-70-13', 'Pirelli', 'Tires & Wheels', 'pc', 2350, 3100, 2, false, $yamahaMaxi],
            ['Inner Tube 2.75-17', 'IRC-TUBE-275-17', 'IRC', 'Tires & Wheels', 'pc', 190, 280, 4, false, ['XRM 125', 'Smash 115', 'TMX 155', 'Barako 175']],
            ['Rear Shock 300mm – Click / Beat', 'RCB-SHK-300', 'RCB', 'Suspension', 'pc', 1200, 1750, 1, false, ['Click 125', 'Beat']],
            ['Fork Oil 10W 500ml', 'MTL-FORK-10W', 'Motul', 'Suspension', 'pc', 280, 390, 2, true, []],
            ['Carb & CVT Cleaner Spray 500ml', 'CLN-CVT-500', 'Generic', 'Engine', 'pc', 120, 190, 4, true, []],
            ['Valve Cover Gasket – Raider R150', '11173-24F00', 'Suzuki Genuine', 'Engine', 'pc', 180, 290, 2, false, ['Raider R150']],
            ['Side Mirror Set (Universal)', 'MIR-UNI-STD', 'Generic', 'Body & Accessories', 'pair', 220, 350, 2, true, []],
        ];

        $brands = Brand::pluck('id', 'name');
        $categories = Category::pluck('id', 'name');
        $units = Unit::pluck('id', 'abbreviation');
        $models = MotorcycleModel::pluck('id', 'name');

        foreach ($parts as [$name, $partNo, $brand, $category, $unit, $cost, $srp, $reorder, $universal, $fits]) {
            $product = Product::firstOrNew(['part_number' => $partNo]);
            $product->fill([
                'name' => $name,
                'brand_id' => $brands[$brand],
                'category_id' => $categories[$category],
                'unit_id' => $units[$unit],
                'avg_cost' => $cost,
                'srp' => $srp,
                'reorder_level' => $reorder,
                'is_universal' => $universal,
                'is_active' => true,
            ])->save();

            $product->fitsModels()->sync(collect($fits)->map(fn (string $model) => $models[$model])->all());
        }

        // code, name, labor fee, minutes, commission type (null = default % from Settings), value
        $services = [
            ['CO', 'Change Oil', 150, 20, null, null],
            ['CVT', 'CVT Cleaning', 350, 60, null, null],
            ['TU', 'Tune-up', 500, 60, null, null],
            ['BRK', 'Brake Cleaning / Adjust', 200, 30, null, null],
            ['VC', 'Valve Clearance', 300, 45, null, null],
            ['FI', 'FI / Carb Cleaning', 450, 60, CommissionType::Percent, 15],
            ['TM', 'Tire Mounting', 100, 20, CommissionType::Fixed, 30],
            ['EC', 'Electrical Check', 250, 45, null, null],
        ];

        foreach ($services as [$code, $name, $fee, $minutes, $type, $value]) {
            Service::updateOrCreate(['code' => $code], [
                'name' => $name, 'labor_fee' => $fee, 'est_minutes' => $minutes,
                'commission_type' => $type, 'commission_value' => $value, 'is_active' => true,
            ]);
        }

        $pms = ServicePackage::updateOrCreate(['name' => 'PMS – Scooter'], [
            'description' => 'Change oil (0.8 L), oil filter and CVT cleaning.',
            'package_price' => 899,
            'is_active' => true,
        ]);

        $pms->items()->delete();
        $pms->items()->createMany([
            ['service_id' => Service::where('code', 'CO')->value('id'), 'qty' => 1, 'sort_order' => 1],
            ['product_id' => Product::where('part_number', 'OIL-BULK-1040')->value('id'), 'qty' => 0.8, 'sort_order' => 2],
            ['product_id' => Product::where('part_number', '15412-K0R-V01')->value('id'), 'qty' => 1, 'sort_order' => 3],
            ['service_id' => Service::where('code', 'CVT')->value('id'), 'qty' => 1, 'sort_order' => 4],
        ]);
    }
}
