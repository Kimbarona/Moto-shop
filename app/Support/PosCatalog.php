<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Mechanic;
use App\Models\MotorcycleMake;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\User;
use App\Settings\InventorySettings;
use App\Settings\PosSettings;
use App\Settings\ServiceSettings;
use App\Settings\TaxSettings;

/**
 * Small, rarely-changing lists the POS / Service Board need on every visit.
 * No cost figures anywhere in here.
 */
class PosCatalog
{
    /**
     * @return array<string, mixed>
     */
    public static function props(User $user): array
    {
        $pos = app(PosSettings::class);
        $tax = app(TaxSettings::class);

        return [
            'categories' => Category::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'icon']),
            'makes' => MotorcycleMake::with(['models' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get()
                ->map(fn ($make) => [
                    'id' => $make->id,
                    'name' => $make->name,
                    'models' => $make->models->map(fn ($model) => ['id' => $model->id, 'name' => $model->name])->values(),
                ]),
            'paymentMethods' => PaymentMethod::active()->orderBy('sort_order')->get(['id', 'name', 'type', 'requires_reference'])
                ->map(fn ($method) => [
                    'id' => $method->id,
                    'name' => $method->name,
                    'type' => $method->type->value,
                    'requires_reference' => $method->requires_reference
                        || ($pos->require_reference_for_non_cash && $method->type->value !== 'cash'),
                ]),
            'services' => Service::active()->orderBy('name')->get(['id', 'code', 'name', 'labor_fee', 'est_minutes'])
                ->map(fn ($service) => [
                    'id' => $service->id,
                    'code' => $service->code,
                    'name' => $service->name,
                    'labor_fee' => (float) $service->labor_fee,
                    'est_minutes' => $service->est_minutes,
                ]),
            'packages' => ServicePackage::active()->with(['items.product', 'items.service'])->orderBy('name')->get()
                ->map(fn (ServicePackage $package) => [
                    'id' => $package->id,
                    'name' => $package->name,
                    'description' => $package->description,
                    'package_price' => $package->package_price !== null ? (float) $package->package_price : null,
                    'regular_price' => round($package->items->sum(fn ($item) => (float) $item->qty * (float) ($item->product?->srp ?? $item->service?->labor_fee ?? 0)), 2),
                    'items' => $package->items->map(fn ($item) => [
                        'name' => $item->product?->name ?? $item->service?->name,
                        'qty' => (float) $item->qty,
                        'type' => $item->product_id ? 'part' : 'labor',
                    ])->values(),
                ]),
            'mechanics' => Mechanic::active()->orderBy('name')->get(['id', 'name', 'user_id']),
            'settings' => [
                'cashierMaxDiscountPercent' => (float) $pos->cashier_max_discount_percent,
                'allowPriceOverride' => $user->isAdmin() || $pos->allow_price_override,
                'defaultPaymentMethodId' => $pos->default_payment_method_id,
                'allowWalkInWithoutCustomer' => $pos->allow_walk_in_without_customer,
                'vatRegistered' => $tax->vat_registered,
                'vatRate' => (float) $tax->vat_rate,
                'pricesVatInclusive' => $tax->prices_vat_inclusive,
                'allowNegativeStock' => app(InventorySettings::class)->allow_negative_stock,
                'showJobTicketOnCreate' => app(ServiceSettings::class)->show_job_ticket_on_create,
            ],
            'openOrdersCount' => static::openOrdersCount($user),
        ];
    }

    public static function openOrdersCount(User $user): int
    {
        return Order::query()->pending()
            ->when($user->isMechanic(), fn ($q) => $q->whereHas('mechanics', fn ($m) => $m->where('user_id', $user->id)))
            ->count();
    }
}
