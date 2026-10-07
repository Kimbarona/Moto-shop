<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Stock\ReceivingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * Opening stock posted through Stock In (so the ledger and average costs are real).
 * A few parts are deliberately low or out of stock for the dashboard.
 */
class OpeningStockSeeder extends Seeder
{
    public function run(): void
    {
        if (StockMovement::exists()) {
            return;
        }

        $user = User::where('email', 'clerk@example.com')->firstOrFail();
        auth()->setUser($user);

        $special = [
            'OIL-BULK-1040' => 40,   // liters
            '11173-24F00' => 0,      // valve cover gasket: out of stock
            'RCB-SHK-300' => 1,      // rear shock: at reorder level
            'PIR-110-70-13' => 1,    // tire: low
            'MTL-YTZ7V' => 2,        // battery: at reorder level
        ];

        $lines = Product::orderBy('id')->get()
            ->map(fn (Product $product) => [
                'product_id' => $product->id,
                'qty' => $special[$product->part_number] ?? max(4, (int) ceil((float) $product->reorder_level * 3)),
                'unit_cost' => (float) $product->avg_cost,
            ])
            ->filter(fn (array $line) => $line['qty'] > 0)
            ->values()
            ->all();

        $service = app(ReceivingService::class);
        $receiving = $service->createDraft([
            'date' => now()->subDays(30)->toDateString(),
            'supplier_id' => Supplier::where('name', 'Motorstar Parts Trading')->value('id'),
            'reference_no' => 'OPENING',
            'notes' => 'Opening stock',
        ], $lines, $user);

        // Post it 30 days back so the stock card reads in order (stock in before sales).
        Date::setTestNow(now()->subDays(30)->setTime(8, 0));

        try {
            $service->post($receiving, $user);
        } finally {
            Date::setTestNow();
        }
    }
}
