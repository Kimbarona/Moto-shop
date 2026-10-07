<?php

namespace Database\Seeders;

use App\Enums\PaymentMethodType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerMotorcycle;
use App\Models\ExpenseCategory;
use App\Models\MotorcycleMake;
use App\Models\MotorcycleModel;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\Unit;
use App\Settings\PosSettings;
use Illuminate\Database\Seeder;

class MasterlistSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['Engine', 'engine'], ['Brakes', 'brake'], ['Electrical', 'battery'], ['Tires & Wheels', 'tire'],
            ['Oil & Lubricants', 'oil'], ['Drive / CVT / Chain', 'chain'], ['Suspension', 'suspension'],
            ['Body & Accessories', 'body'], ['Filters', 'filter'],
        ];

        foreach ($categories as $i => [$name, $icon]) {
            Category::updateOrCreate(['name' => $name], ['icon' => $icon, 'sort_order' => $i + 1]);
        }

        foreach ([['Piece', 'pc', false], ['Set', 'set', false], ['Pair', 'pair', false], ['Liter', 'L', true], ['Meter', 'm', true]] as [$name, $abbr, $decimal]) {
            Unit::updateOrCreate(['abbreviation' => $abbr], ['name' => $name, 'allows_decimal' => $decimal]);
        }

        foreach (['Honda Genuine', 'Yamaha Genuine', 'Suzuki Genuine', 'Motul', 'Shell', 'Yamalube', 'NGK', 'Bendix',
            'Motolite', 'IRC', 'Pirelli', 'Bando', 'RK', 'Osram', 'Prestone', 'RCB', 'Generic'] as $brand) {
            Brand::updateOrCreate(['name' => $brand]);
        }

        $makes = [
            'Honda' => [['Click 125', 125, 2018], ['Click 160', 160, 2022], ['Beat', 110, 2015], ['ADV 160', 160, 2022], ['XRM 125', 125, 2014], ['TMX 155', 155, 2008]],
            'Yamaha' => [['NMAX', 155, 2016], ['Aerox', 155, 2017], ['Mio i 125', 125, 2016], ['Sniper 155', 155, 2021]],
            'Suzuki' => [['Raider R150', 150, 2016], ['Smash 115', 115, 2010], ['Burgman Street', 125, 2020]],
            'Kawasaki' => [['Barako 175', 175, 2011]],
        ];

        foreach ($makes as $makeName => $models) {
            $make = MotorcycleMake::updateOrCreate(['name' => $makeName]);

            foreach ($models as [$model, $cc, $from]) {
                MotorcycleModel::updateOrCreate(
                    ['motorcycle_make_id' => $make->id, 'name' => $model],
                    ['engine_cc' => $cc, 'year_from' => $from],
                );
            }
        }

        $methods = [
            ['Cash', PaymentMethodType::Cash, false],
            ['GCash', PaymentMethodType::Ewallet, true],
            ['Maya', PaymentMethodType::Ewallet, true],
            ['Bank Transfer', PaymentMethodType::Bank, true],
        ];

        foreach ($methods as $i => [$name, $type, $ref]) {
            PaymentMethod::updateOrCreate(['name' => $name], [
                'type' => $type, 'requires_reference' => $ref, 'is_active' => true, 'sort_order' => $i + 1,
            ]);
        }

        $pos = app(PosSettings::class);
        $pos->default_payment_method_id = PaymentMethod::where('name', 'Cash')->value('id');
        $pos->save();

        $suppliers = [
            ['Motorstar Parts Trading', 'Ramon Uy', '0917 800 1111', 'Banawe St., Quezon City', '30 days'],
            ['Yamaha Genuine Parts Distributor', 'Liza Cruz', '0918 800 2222', 'Pasig City', 'COD'],
            ['Petron Lubes Dealer', 'Noel Garcia', '0919 800 3333', 'Caloocan City', '15 days'],
        ];

        foreach ($suppliers as [$name, $contact, $mobile, $address, $terms]) {
            Supplier::updateOrCreate(['name' => $name], [
                'contact_person' => $contact, 'mobile' => $mobile, 'address' => $address, 'terms' => $terms,
            ]);
        }

        foreach (['Rent', 'Electricity', 'Water', 'Salaries', 'Shop Supplies', 'Transportation', 'Miscellaneous'] as $name) {
            ExpenseCategory::updateOrCreate(['name' => $name]);
        }

        $customers = [
            ['Juan Dela Cruz', '0917 123 4567', 'Brgy. San Isidro, Antipolo', 'Click 125', 'NAB 1234', 2021, 'Red', 18250],
            ['Maria Santos', '0918 234 5678', 'Cainta, Rizal', 'NMAX', 'NCR 4567', 2022, 'Matte Black', 9400],
            ['Pedro Reyes', '0919 345 6789', 'Marikina City', 'XRM 125', '1234 AB', 2019, 'Blue', 35600],
            ['Ana Lim', '0920 456 7890', 'Taytay, Rizal', 'Aerox', null, 2025, 'White', 300],
        ];

        foreach ($customers as [$name, $mobile, $address, $model, $plate, $year, $color, $odo]) {
            $customer = Customer::updateOrCreate(['name' => $name], ['mobile' => $mobile, 'address' => $address]);

            CustomerMotorcycle::updateOrCreate(
                ['customer_id' => $customer->id, 'motorcycle_model_id' => MotorcycleModel::where('name', $model)->value('id')],
                ['plate_no' => $plate, 'year' => $year, 'color' => $color, 'last_odometer' => $odo],
            );
        }
    }
}
