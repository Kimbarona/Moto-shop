<?php

namespace App\Services\Orders;

use App\Enums\CommissionType;
use App\Models\OrderItem;
use App\Settings\ServiceSettings;

/**
 * Mechanic commission on labor lines, computed at payment on the NET labor amount
 * (after line and order discounts). Uses the service's own commission setting, or the
 * default % from Settings when the service has none.
 */
class CommissionService
{
    public function __construct(private readonly ServiceSettings $settings) {}

    public function forLine(OrderItem $item): float
    {
        if (! $item->isLabor() || $item->mechanic_id === null) {
            return 0.0;
        }

        $net = max(0.0, $item->netAmount());
        $service = $item->service;

        $commission = match ($service?->commission_type) {
            CommissionType::None => 0.0,
            CommissionType::Fixed => (float) $service->commission_value * (float) $item->qty,
            CommissionType::Percent => $net * (float) $service->commission_value / 100,
            default => $net * (float) $this->settings->default_commission_percent / 100,
        };

        // Never more than the labor actually charged, never negative.
        return round(max(0.0, min($commission, $net)), 2);
    }

    /**
     * Commission to take back when $qty of a labor line is refunded (pro rata).
     */
    public function reversalFor(OrderItem $item, float $qty): float
    {
        if ((float) $item->qty <= 0) {
            return 0.0;
        }

        return round((float) $item->commission_amount * $qty / (float) $item->qty, 2);
    }
}
