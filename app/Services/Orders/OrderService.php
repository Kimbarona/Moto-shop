<?php

namespace App\Services\Orders;

use App\Enums\ApprovalAction;
use App\Enums\DiscountType;
use App\Enums\LineType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ServiceStatus;
use App\Models\CustomerMotorcycle;
use App\Models\Mechanic;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\Approvals\ManagerPinService;
use App\Services\Stock\StockService;
use App\Settings\InventorySettings;
use App\Settings\PosSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Pending orders: create, add / change / remove lines, packages, discounts, service status.
 *
 * Reservation: parts on Pending orders are "reserved" (computed by SUM, never stored).
 * Adding parts requires Available = On Hand − Reserved ≥ qty, checked while the product
 * row is locked FOR UPDATE, so two orders can never reserve the same last unit.
 * Removing a line or voiding the order releases the reservation automatically.
 *
 * Lock order is always: order row → product rows (ascending id).
 */
class OrderService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly PricingService $pricing,
        private readonly ManagerPinService $pins,
        private readonly InventorySettings $inventory,
        private readonly PosSettings $pos,
    ) {}

    /**
     * @param  array{customer_id?: int|null, customer_motorcycle_id?: int|null, odometer_in?: int|null, complaint?: string|null, notes?: string|null, mechanic_ids?: list<int>}  $attributes
     */
    public function create(User $actor, OrderType $type, array $attributes = []): Order
    {
        Gate::forUser($actor)->authorize('create', Order::class);

        if ($type === OrderType::Service && empty($attributes['customer_id'])) {
            throw ValidationException::withMessages(['customer_id' => 'A service order needs a customer.']);
        }

        $this->validateMotorcycle($attributes['customer_id'] ?? null, $attributes['customer_motorcycle_id'] ?? null);

        return DB::transaction(function () use ($actor, $type, $attributes) {
            $order = Order::create([
                'type' => $type,
                'status' => OrderStatus::Pending,
                'service_status' => $type === OrderType::Service ? ServiceStatus::Waiting : null,
                'customer_id' => $attributes['customer_id'] ?? null,
                'customer_motorcycle_id' => $attributes['customer_motorcycle_id'] ?? null,
                'odometer_in' => $attributes['odometer_in'] ?? null,
                'complaint' => $attributes['complaint'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->syncMechanics($order, $attributes['mechanic_ids'] ?? []);

            return $order;
        });
    }

    /**
     * @param  array{customer_id?: int|null, customer_motorcycle_id?: int|null, odometer_in?: int|null, complaint?: string|null, notes?: string|null, mechanic_ids?: list<int>}  $attributes
     */
    public function updateDetails(Order $order, User $actor, array $attributes): Order
    {
        return DB::transaction(function () use ($order, $actor, $attributes) {
            $order = $this->lockPending($order, $actor);

            $customerId = array_key_exists('customer_id', $attributes) ? $attributes['customer_id'] : $order->customer_id;
            $bikeId = array_key_exists('customer_motorcycle_id', $attributes) ? $attributes['customer_motorcycle_id'] : $order->customer_motorcycle_id;

            if ($order->isService() && empty($customerId)) {
                throw ValidationException::withMessages(['customer_id' => 'A service order needs a customer.']);
            }

            $this->validateMotorcycle($customerId, $bikeId);

            $order->fill(collect($attributes)->only(['customer_id', 'customer_motorcycle_id', 'odometer_in', 'complaint', 'notes'])->all())->save();

            if (array_key_exists('mechanic_ids', $attributes)) {
                $this->syncMechanics($order, $attributes['mechanic_ids']);
            }

            return $order->refresh();
        });
    }

    public function addPart(Order $order, User $actor, int $productId, float $qty = 1): OrderItem
    {
        return DB::transaction(function () use ($order, $actor, $productId, $qty) {
            $order = $this->lockPending($order, $actor);
            $product = $this->lockedProduct($productId);
            $this->validateQty($product, $qty);

            $existing = $order->items()
                ->where('line_type', LineType::Part)
                ->where('product_id', $product->id)
                ->whereNull('service_package_id')
                ->first();

            $this->ensureAvailable($product, $qty);

            if ($existing) {
                $existing->qty = round((float) $existing->qty + $qty, 2);
                $existing->save();
                $item = $existing;
            } else {
                $item = $order->items()->create([
                    'line_type' => LineType::Part,
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'qty' => $qty,
                    'original_price' => $product->srp,
                    'unit_price' => $product->srp,
                    'sort_order' => $this->nextSortOrder($order),
                ]);
            }

            $this->pricing->recalculate($order);

            return $item->refresh();
        });
    }

    public function addLabor(Order $order, User $actor, int $serviceId, float $qty = 1, ?int $mechanicId = null): OrderItem
    {
        return DB::transaction(function () use ($order, $actor, $serviceId, $qty, $mechanicId) {
            $order = $this->lockPending($order, $actor);
            $service = Service::query()->active()->find($serviceId)
                ?? throw ValidationException::withMessages(['service_id' => 'This service is not available.']);

            if ($qty <= 0 || floor($qty) != $qty) {
                throw ValidationException::withMessages(['qty' => 'Labor quantity must be a whole number.']);
            }

            $item = $order->items()->create([
                'line_type' => LineType::Labor,
                'service_id' => $service->id,
                'description' => $service->name,
                'qty' => $qty,
                'original_price' => $service->labor_fee,
                'unit_price' => $service->labor_fee,
                'mechanic_id' => $this->resolveMechanic($order, $actor, $mechanicId),
                'sort_order' => $this->nextSortOrder($order),
            ]);

            $this->pricing->recalculate($order);

            return $item->refresh();
        });
    }

    /**
     * Expand a package into individual lines. A fixed package price below the regular
     * total is applied as pre-approved line discounts (no PIN needed); a package price at
     * or above the regular total charges the regular prices.
     *
     * @return Collection<int, OrderItem>
     */
    public function addPackage(Order $order, User $actor, int $packageId): Collection
    {
        return DB::transaction(function () use ($order, $actor, $packageId) {
            $order = $this->lockPending($order, $actor);

            $package = ServicePackage::query()->active()->with(['items.product.unit', 'items.service'])->find($packageId)
                ?? throw ValidationException::withMessages(['service_package_id' => 'This package is not available.']);

            $partQty = $package->items->whereNotNull('product_id')
                ->groupBy('product_id')
                ->map(fn ($lines) => $lines->sum(fn ($line) => (float) $line->qty));

            $products = $this->stock->lockProducts($partQty->keys());

            foreach ($partQty as $productId => $qty) {
                $product = $products[$productId] ?? null;

                if (! $product || ! $product->is_active || $product->trashed()) {
                    throw ValidationException::withMessages(['service_package_id' => 'A part in this package is no longer sold.']);
                }

                $this->ensureAvailable($product, $qty);
            }

            $lines = $package->items->map(function ($line) {
                $isPart = $line->product_id !== null;
                $price = (float) ($isPart ? $line->product->srp : $line->service->labor_fee);

                return [
                    'line' => $line,
                    'is_part' => $isPart,
                    'price' => $price,
                    'regular' => round($price * (float) $line->qty, 2),
                ];
            });

            $allocated = $this->packageAllocation($lines->pluck('regular')->all(), $package->package_price !== null ? (float) $package->package_price : null);
            $sort = $this->nextSortOrder($order);
            $created = collect();

            foreach ($lines->values() as $i => $line) {
                $discount = round($line['regular'] - $allocated[$i], 2);

                $created->push($order->items()->create([
                    'line_type' => $line['is_part'] ? LineType::Part : LineType::Labor,
                    'product_id' => $line['line']->product_id,
                    'service_id' => $line['line']->service_id,
                    'service_package_id' => $package->id,
                    'description' => $line['is_part'] ? $line['line']->product->name : $line['line']->service->name,
                    'qty' => $line['line']->qty,
                    'original_price' => $line['price'],
                    'unit_price' => $line['price'],
                    'discount_type' => $discount > 0 ? DiscountType::Amount : null,
                    'discount_value' => max(0, $discount),
                    'mechanic_id' => $line['is_part'] ? null : $this->resolveMechanic($order, $actor, null),
                    'sort_order' => $sort++,
                ]));
            }

            $this->pricing->recalculate($order);

            activity('Order')->causedBy($actor)->performedOn($order)->event('package_added')
                ->withProperties(['package' => $package->name, 'package_price' => $package->package_price])
                ->log("Package {$package->name} added");

            return $created->map->refresh();
        });
    }

    /**
     * Change qty, price, line discount or mechanic of a Pending line.
     *
     * @param  array{qty?: float|string, unit_price?: float|string, discount_type?: DiscountType|string|null, discount_value?: float|string|null, mechanic_id?: int|null}  $changes
     */
    public function updateLine(Order $order, OrderItem $item, User $actor, array $changes, ?string $pin = null, bool $preApproved = false): OrderItem
    {
        $this->assertLineBelongs($order, $item);

        $pricingChange = array_key_exists('unit_price', $changes) || array_key_exists('discount_type', $changes) || array_key_exists('discount_value', $changes);

        if ($pricingChange && ! $preApproved) {
            $this->approvePricingChange($order, $item, $actor, $changes, $pin);
        }

        return DB::transaction(function () use ($order, $item, $actor, $changes, $pricingChange) {
            $order = $this->lockPending($order, $actor, $pricingChange ? 'discount' : 'editItems');
            $item = $order->items()->whereKey($item->getKey())->firstOrFail();

            if (array_key_exists('qty', $changes)) {
                $qty = round((float) $changes['qty'], 2);

                if ($item->isPart()) {
                    $product = $this->lockedProduct($item->product_id, mustBeActive: false);
                    $this->validateQty($product, $qty);
                    $extra = round($qty - (float) $item->qty, 2);

                    if ($extra > 0) {
                        $this->ensureAvailable($product, $extra);
                    }
                } elseif ($qty <= 0 || floor($qty) != $qty) {
                    throw ValidationException::withMessages(['qty' => 'Labor quantity must be a whole number.']);
                }

                $item->qty = $qty;
            }

            if (array_key_exists('unit_price', $changes)) {
                $price = round((float) $changes['unit_price'], 2);

                if ($price < 0) {
                    throw ValidationException::withMessages(['unit_price' => 'Price cannot be negative.']);
                }

                $item->unit_price = $price;
            }

            if (array_key_exists('discount_type', $changes) || array_key_exists('discount_value', $changes)) {
                [$type, $value] = $this->normalizeDiscount($changes['discount_type'] ?? $item->discount_type, $changes['discount_value'] ?? $item->discount_value);
                $item->discount_type = $type;
                $item->discount_value = $value;
            }

            if (array_key_exists('mechanic_id', $changes)) {
                if (! $item->isLabor()) {
                    throw ValidationException::withMessages(['mechanic_id' => 'Only labor lines have a mechanic.']);
                }

                $item->mechanic_id = $this->validMechanicId($changes['mechanic_id']);
            }

            $item->save();
            $this->pricing->recalculate($order);

            return $item->refresh();
        });
    }

    public function removeLine(Order $order, OrderItem $item, User $actor): void
    {
        $this->assertLineBelongs($order, $item);

        DB::transaction(function () use ($order, $item, $actor) {
            $order = $this->lockPending($order, $actor);
            $order->items()->whereKey($item->getKey())->firstOrFail()->delete();
            $this->pricing->recalculate($order);
        });
    }

    public function setOrderDiscount(Order $order, User $actor, DiscountType|string|null $type, float|string|null $value, ?string $pin = null, bool $preApproved = false): Order
    {
        [$type, $value] = $this->normalizeDiscount($type, $value);

        if (! $preApproved) {
            Gate::forUser($actor)->authorize('discount', $order);

            $new = $this->pricing->orderDiscountPercent($order, $type, $value);
            $old = $this->pricing->orderDiscountPercent($order, $order->discount_type, (float) $order->discount_value);

            if ($new > $old && $this->pricing->exceedsCashierLimit($actor, $new)) {
                $this->requirePin($actor, $pin, ApprovalAction::Discount, $order, $new, ['scope' => 'order', 'type' => $type?->value, 'value' => $value]);
            }
        }

        return DB::transaction(function () use ($order, $actor, $type, $value) {
            $order = $this->lockPending($order, $actor, 'discount');
            $order->forceFill(['discount_type' => $type, 'discount_value' => $value])->save();

            return $this->pricing->recalculate($order);
        });
    }

    public function setServiceStatus(Order $order, User $actor, ServiceStatus $status): Order
    {
        return DB::transaction(function () use ($order, $actor, $status) {
            $order = $this->lockPending($order, $actor, 'updateServiceStatus');

            if (! $order->isService()) {
                throw ValidationException::withMessages(['service_status' => 'Only service orders have a service status.']);
            }

            if ($status === ServiceStatus::Done && ! $order->items()->exists()) {
                throw ValidationException::withMessages(['service_status' => 'Add the parts and labor used before marking the job done.']);
            }

            $order->forceFill([
                'service_status' => $status,
                'done_at' => $status === ServiceStatus::Done ? now() : null,
            ])->save();

            return $order;
        });
    }

    /**
     * Lock the order row, make sure it is still Pending, and authorize the actor.
     */
    public function lockPending(Order $order, User $actor, string $ability = 'editItems'): Order
    {
        /** @var Order $locked */
        $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

        if (! $locked->isPending()) {
            throw ValidationException::withMessages([
                'order' => "Order {$locked->order_no} is {$locked->status->getLabel()} and locked. Changes after payment go through Return / Refund.",
            ]);
        }

        Gate::forUser($actor)->authorize($ability, $locked);

        return $locked;
    }

    /**
     * @throws ValidationException when Available (On Hand − Reserved) is short
     */
    public function ensureAvailable(Product $product, float $qty): void
    {
        if ($this->inventory->allow_negative_stock) {
            return;
        }

        $onHand = (float) $product->qty_on_hand;
        $reserved = $product->reservedQty();
        $available = round($onHand - $reserved, 2);

        if ($available < $qty) {
            throw ValidationException::withMessages([
                'qty' => sprintf(
                    'Only %s %s of %s available (on hand %s, reserved %s).',
                    $this->number(max(0, $available)),
                    $product->unit?->abbreviation ?? '',
                    $product->name,
                    $this->number($onHand),
                    $this->number($reserved),
                ),
            ]);
        }
    }

    /**
     * Discounts / price overrides above the cashier limit need a manager PIN. Lowering an
     * existing discount, or raising a price, never does. Called before any transaction.
     *
     * @param  array<string, mixed>  $changes
     */
    private function approvePricingChange(Order $order, OrderItem $item, User $actor, array $changes, ?string $pin): void
    {
        Gate::forUser($actor)->authorize('discount', $order);

        $priceLowered = array_key_exists('unit_price', $changes) && (float) $changes['unit_price'] < (float) $item->unit_price;

        if ($priceLowered && ! $actor->isAdmin() && ! $this->pos->allow_price_override) {
            throw new AuthorizationException('Price override is turned off in Settings. Ask a manager.');
        }

        $probe = $item->replicate();
        $probe->forceFill(collect($changes)->only(['qty', 'unit_price'])->all());

        if (array_key_exists('discount_type', $changes) || array_key_exists('discount_value', $changes)) {
            [$type, $value] = $this->normalizeDiscount($changes['discount_type'] ?? $item->discount_type, $changes['discount_value'] ?? $item->discount_value);
            $probe->discount_type = $type;
            $probe->discount_value = $value;
        }

        $new = $this->pricing->lineReductionPercent($probe);
        $old = $this->pricing->lineReductionPercent($item->replicate());

        if ($new > $old && $this->pricing->exceedsCashierLimit($actor, $new)) {
            $this->requirePin($actor, $pin, $priceLowered ? ApprovalAction::PriceOverride : ApprovalAction::Discount, $order, $new, [
                'scope' => 'line',
                'line' => $item->description,
                'changes' => collect($changes)->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)->all(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     *
     * @throws ValidationException with key "pin" when no / wrong PIN
     */
    public function requirePin(User $actor, ?string $pin, ApprovalAction $action, ?Order $order, float $percent, array $details = []): void
    {
        $limit = (float) $this->pos->cashier_max_discount_percent;

        if (blank($pin)) {
            throw ValidationException::withMessages([
                'pin' => sprintf('Manager PIN required: %s%% is above the cashier limit of %s%%.', $this->number($percent), $this->number($limit)),
            ]);
        }

        $this->pins->authorize($actor, $action, $pin, $order, [...$details, 'percent' => $percent, 'limit' => $limit]);
    }

    /**
     * @return array{0: DiscountType|null, 1: float}
     */
    public function normalizeDiscount(DiscountType|string|null $type, float|string|null $value): array
    {
        $type = $type instanceof DiscountType ? $type : (filled($type) ? DiscountType::from($type) : null);
        $value = round((float) ($value ?? 0), 2);

        if ($value < 0) {
            throw ValidationException::withMessages(['discount_value' => 'Discount cannot be negative.']);
        }

        if ($type === DiscountType::Percent && $value > 100) {
            throw ValidationException::withMessages(['discount_value' => 'Discount cannot be more than 100%.']);
        }

        return $value == 0.0 ? [null, 0.0] : [$type ?? DiscountType::Amount, $value];
    }

    /**
     * Allocate a package price over its lines in proportion to their regular value.
     *
     * @param  list<float>  $regular
     * @return list<float>
     */
    public function packageAllocation(array $regular, ?float $packagePrice): array
    {
        $total = array_sum($regular);

        if ($packagePrice === null || $total <= 0 || $packagePrice >= $total) {
            return $regular;
        }

        $allocated = [];
        $remaining = $packagePrice;

        foreach ($regular as $i => $value) {
            $share = $i === count($regular) - 1 ? round($remaining, 2) : round($packagePrice * $value / $total, 2);
            $allocated[] = $share;
            $remaining -= $share;
        }

        return $allocated;
    }

    private function lockedProduct(int $productId, bool $mustBeActive = true): Product
    {
        $product = $this->stock->lockProducts([$productId])->first()
            ?? throw ValidationException::withMessages(['product_id' => 'Part not found.']);

        if ($mustBeActive && (! $product->is_active || $product->trashed())) {
            throw ValidationException::withMessages(['product_id' => "{$product->name} is not sold anymore."]);
        }

        return $product->load('unit');
    }

    private function validateQty(Product $product, float $qty): void
    {
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => 'Quantity must be more than zero.']);
        }

        if (! ($product->unit?->allows_decimal ?? false) && floor($qty) != $qty) {
            throw ValidationException::withMessages(['qty' => "{$product->name} is sold per {$product->unit?->name}: use a whole number."]);
        }
    }

    private function validateMotorcycle(?int $customerId, ?int $motorcycleId): void
    {
        if ($motorcycleId === null) {
            return;
        }

        $belongs = CustomerMotorcycle::whereKey($motorcycleId)->where('customer_id', $customerId)->exists();

        if (! $belongs) {
            throw ValidationException::withMessages(['customer_motorcycle_id' => 'This motorcycle does not belong to the selected customer.']);
        }
    }

    /**
     * @param  list<int>  $mechanicIds
     */
    private function syncMechanics(Order $order, array $mechanicIds): void
    {
        $ids = Mechanic::query()->active()->whereIn('id', $mechanicIds)->pluck('id');

        if ($ids->count() !== count(array_unique($mechanicIds))) {
            throw ValidationException::withMessages(['mechanic_ids' => 'Pick active mechanics only.']);
        }

        $order->mechanics()->sync($ids);
    }

    /**
     * Labor goes to: the mechanic given → the mechanic adding it → the order's first mechanic.
     */
    private function resolveMechanic(Order $order, User $actor, ?int $mechanicId): ?int
    {
        if ($mechanicId !== null) {
            return $this->validMechanicId($mechanicId);
        }

        if ($actor->isMechanic() && $actor->mechanic) {
            return $actor->mechanic->id;
        }

        return $order->mechanics()->orderBy('mechanics.id')->value('mechanics.id');
    }

    private function validMechanicId(?int $mechanicId): ?int
    {
        if ($mechanicId === null) {
            return null;
        }

        return Mechanic::query()->active()->whereKey($mechanicId)->value('id')
            ?? throw ValidationException::withMessages(['mechanic_id' => 'Pick an active mechanic.']);
    }

    private function assertLineBelongs(Order $order, OrderItem $item): void
    {
        if ((int) $item->order_id !== (int) $order->getKey()) {
            throw ValidationException::withMessages(['item' => 'This line is not on this order.']);
        }
    }

    private function nextSortOrder(Order $order): int
    {
        return (int) $order->items()->max('sort_order') + 1;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
