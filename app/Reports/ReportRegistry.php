<?php

namespace App\Reports;

use App\Models\User;

/**
 * Every report, in the order shown on the Reports page.
 */
final class ReportRegistry
{
    /**
     * @var list<class-string<Report>>
     */
    public const REPORTS = [
        Sales\DailySalesReport::class,
        Sales\SalesByItemReport::class,
        Sales\SalesByPaymentMethodReport::class,
        Sales\SalesByCashierReport::class,
        Sales\PartsVsLaborReport::class,
        Profit\GrossProfitReport::class,
        Profit\ProfitAndLossReport::class,
        Inventory\InventoryValuationReport::class,
        Inventory\StockMovementSummaryReport::class,
        Inventory\LowStockReport::class,
        Inventory\MovingStockReport::class,
        Inventory\DefectiveStockReport::class,
        Inventory\AdjustmentVarianceReport::class,
        Service\ServiceOrdersByStatusReport::class,
        Service\MechanicProductivityReport::class,
        Service\MechanicCommissionReport::class,
        Service\ServiceHistoryReport::class,
        Returns\ReturnsReport::class,
        Audit\VoidsReport::class,
        Audit\ApprovalsReport::class,
        Audit\ReprintsReport::class,
        Audit\PriceChangesReport::class,
        Expenses\ExpensesByCategoryReport::class,
    ];

    /**
     * @return class-string<Report>|null
     */
    public static function find(?string $key): ?string
    {
        foreach (self::REPORTS as $class) {
            if ($class::key() === $key) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Reports this user may open, grouped: [group => [class, …]].
     *
     * @return array<string, list<class-string<Report>>>
     */
    public static function grouped(?User $user): array
    {
        $groups = [];

        foreach (self::REPORTS as $class) {
            if ($class::canBeViewedBy($user)) {
                $groups[$class::group()][] = $class;
            }
        }

        return $groups;
    }
}
