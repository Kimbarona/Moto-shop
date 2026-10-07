<?php

namespace Database\Seeders;

use App\Enums\OrderType;
use App\Enums\ServiceStatus;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\PaymentService;
use App\Services\Orders\ReturnService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Two weeks of sales, one customer return, and a few open service jobs, all created through the real
 * services (stock, slip numbers, commission) so the dashboard and reports have data.
 */
class TransactionSeeder extends Seeder
{
    public function run(): void
    {
        if (Order::exists()) {
            return;
        }

        mt_srand(2026);
        // Fixed base date: inside the loop Carbon::today() returns the faked date.
        $today = Carbon::today();

        $cashier = User::where('email', 'cashier@example.com')->firstOrFail();
        $orders = app(OrderService::class);
        $payments = app(PaymentService::class);
        $methods = PaymentMethod::orderBy('sort_order')->get()->keyBy('name');
        $mechanics = Mechanic::orderBy('id')->get();
        // Parts the open jobs use at the end are kept out of the random sales, so they
        // can never be sold out first.
        $heldBack = Product::whereIn('part_number', ['BP-YMH-MAXI', 'CS-428-XRM'])->pluck('id')
            ->merge(ServicePackage::where('name', 'PMS – Scooter')->first()?->items->pluck('product_id') ?? [])
            ->filter();
        $products = Product::where('is_active', true)->whereNotIn('id', $heldBack)->get();
        $services = Service::where('is_active', true)->get();

        try {
            foreach (range(14, 1) as $daysAgo) {
                foreach (range(1, mt_rand(2, 4)) as $n) {
                    Date::setTestNow($today->copy()->subDays($daysAgo)->setTime(mt_rand(8, 17), mt_rand(0, 59)));
                    auth()->setUser($cashier);

                    $lines = $products->shuffle()->take(mt_rand(1, 3))
                        ->filter(fn (Product $p) => $p->fresh()->availableQty() >= 1)
                        ->map(fn (Product $p) => ['type' => 'part', 'product_id' => $p->id, 'qty' => 1])
                        ->values()->all();

                    if ($lines === []) {
                        continue;
                    }

                    $method = mt_rand(1, 10) <= 7 ? $methods['Cash'] : $methods['GCash'];

                    $payments->checkout($cashier, $lines, [], [
                        'payment_method_id' => $method->id,
                        'amount_tendered' => 5000,
                        'reference_no' => $method->requires_reference ? 'GC'.mt_rand(100000000, 999999999) : null,
                    ]);
                }

                // A paid service job most days.
                if ($daysAgo % 2 === 0) {
                    Date::setTestNow($today->copy()->subDays($daysAgo)->setTime(10, 30));
                    $customer = Customer::with('motorcycles')->inRandomOrder()->first();
                    $bike = $customer->motorcycles->first();

                    $order = $orders->create($cashier, OrderType::Service, [
                        'customer_id' => $customer->id,
                        'customer_motorcycle_id' => $bike?->id,
                        'odometer_in' => ($bike?->last_odometer ?? 0) + mt_rand(300, 1500),
                        'complaint' => 'Change oil and check-up',
                        'mechanic_ids' => [$mechanics->random()->id],
                    ]);
                    $orders->addLabor($order, $cashier, $services->random()->id);
                    $orders->addLabor($order, $cashier, $services->random()->id);

                    Date::setTestNow($today->copy()->subDays($daysAgo)->setTime(15, 0));
                    $payments->pay($order, $cashier, ['payment_method_id' => $methods['Cash']->id, 'amount_tendered' => 5000]);
                }
            }

            $this->sampleReturn($today, $methods['Cash']);
        } finally {
            Date::setTestNow();
        }

        $this->openJobs($cashier, $orders, $mechanics);
    }

    /**
     * One customer return: one part from a walk-in sale a few days before, back to stock,
     * refunded in cash (processed by the manager, so no PIN).
     */
    private function sampleReturn(Carbon $today, PaymentMethod $cash): void
    {
        $order = Order::query()->paid()->where('type', OrderType::WalkIn)
            ->where('paid_at', '<', $today->copy()->subDays(4))
            ->whereHas('items', fn ($q) => $q->where('line_type', 'part'))
            ->has('items', '>=', 2)
            ->latest('paid_at')
            ->first();

        if (! $order) {
            return;
        }

        $manager = User::where('email', 'admin@example.com')->firstOrFail();
        $line = $order->items()->where('line_type', 'part')->first();

        Date::setTestNow($today->copy()->subDays(3)->setTime(14, 10));
        auth()->setUser($manager);

        app(ReturnService::class)->process($order, $manager, [
            'reason' => 'not_needed',
            'remarks' => 'Customer bought the wrong size; unopened.',
            'resolution' => 'refund',
            'refund_method_id' => $cash->id,
            'items' => [['order_item_id' => $line->id, 'qty' => 1, 'disposition' => 'back_to_stock']],
        ]);
    }

    private function openJobs(User $cashier, OrderService $orders, $mechanics): void
    {
        $boy = $mechanics->firstWhere('name', 'Mang Boy Santos') ?? $mechanics->first();
        $jun = $mechanics->firstWhere('name', 'Jun Ramirez') ?? $mechanics->last();

        // PMS on Juan's Click — in progress.
        $juan = Customer::where('name', 'Juan Dela Cruz')->with('motorcycles')->first();
        $pms = $orders->create($cashier, OrderType::Service, [
            'customer_id' => $juan->id,
            'customer_motorcycle_id' => $juan->motorcycles->first()?->id,
            'odometer_in' => 18950,
            'complaint' => 'PMS – change oil, CVT cleaning',
            'mechanic_ids' => [$boy->id],
        ]);
        $orders->addPackage($pms, $cashier, ServicePackage::where('name', 'PMS – Scooter')->value('id'));
        $orders->setServiceStatus($pms, $cashier, ServiceStatus::InProgress);

        // Maria's NMAX — waiting for a mechanic.
        $maria = Customer::where('name', 'Maria Santos')->with('motorcycles')->first();
        $brakes = $orders->create($cashier, OrderType::Service, [
            'customer_id' => $maria->id,
            'customer_motorcycle_id' => $maria->motorcycles->first()?->id,
            'odometer_in' => 9870,
            'complaint' => 'Front brake squeaks',
            'mechanic_ids' => [$jun->id],
        ]);
        $orders->addPart($brakes, $cashier, Product::where('part_number', 'BP-YMH-MAXI')->value('id'), 1);
        $orders->addLabor($brakes, $cashier, Service::where('code', 'BRK')->value('id'));

        // Pedro's XRM — dropped off yesterday, still open (> 24h).
        $pedro = Customer::where('name', 'Pedro Reyes')->with('motorcycles')->first();
        $old = $orders->create($cashier, OrderType::Service, [
            'customer_id' => $pedro->id,
            'customer_motorcycle_id' => $pedro->motorcycles->first()?->id,
            'odometer_in' => 36010,
            'complaint' => 'Chain noisy, replace chain & sprocket',
            'mechanic_ids' => [$boy->id],
        ]);
        $orders->addPart($old, $cashier, Product::where('part_number', 'CS-428-XRM')->value('id'), 1);
        $orders->addLabor($old, $cashier, Service::where('code', 'TU')->value('id'));
        $old->forceFill(['created_at' => now()->subHours(30)])->saveQuietly();
    }
}
