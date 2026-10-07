<?php

use App\Enums\AdjustmentReason;
use App\Enums\DefectiveStatus;
use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Models\Category;
use App\Models\StockMovement;
use App\Models\StockReceiving;
use App\Models\Supplier;
use App\Services\Stock\AdjustmentService;
use App\Services\Stock\DefectiveStockService;
use App\Services\Stock\PhysicalCountService;
use App\Services\Stock\ReceivingService;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = clerk();
    $this->actingAs($this->user);
});

function receive(array $lines, $user): StockReceiving
{
    $service = app(ReceivingService::class);
    $receiving = $service->createDraft(['date' => today()->toDateString(), 'reference_no' => 'DR-1001'], $lines, $user);

    return $service->post($receiving, $user);
}

describe('stock in', function () {
    it('posts stock_in movements and weighted average cost', function () {
        $filter = stockProduct(['name' => 'Oil Filter']);
        $plug = stockProduct(['name' => 'Spark Plug']);

        receive([
            ['product_id' => $filter->id, 'qty' => 10, 'unit_cost' => 120],
            ['product_id' => $plug->id, 'qty' => 20, 'unit_cost' => 95],
        ], $this->user);

        $receiving = receive([['product_id' => $filter->id, 'qty' => 10, 'unit_cost' => 140]], $this->user);

        expect($receiving->status)->toBe(DocumentStatus::Posted)
            ->and($receiving->posted_by)->toBe($this->user->id)
            ->and((float) $filter->fresh()->qty_on_hand)->toBe(20.0)
            ->and((float) $filter->fresh()->avg_cost)->toBe(130.0)
            ->and((float) $plug->fresh()->qty_on_hand)->toBe(20.0)
            ->and($receiving->movements()->first()->type)->toBe(MovementType::StockIn)
            ->and($receiving->movements()->first()->remarks)->toContain('DR-1001');
    });

    it('cannot be posted twice', function () {
        $product = stockProduct();
        $receiving = receive([['product_id' => $product->id, 'qty' => 1, 'unit_cost' => 10]], $this->user);

        expect(fn () => app(ReceivingService::class)->post($receiving, $this->user))->toThrow(ValidationException::class);
        expect((float) $product->fresh()->qty_on_hand)->toBe(1.0);
    });

    it('rejects zero or negative quantities', function () {
        $product = stockProduct();

        expect(fn () => receive([['product_id' => $product->id, 'qty' => 0, 'unit_cost' => 10]], $this->user))
            ->toThrow(ValidationException::class);
    });

    it('cancels drafts without touching stock', function () {
        $product = stockProduct();
        $service = app(ReceivingService::class);
        $draft = $service->createDraft(['date' => today()->toDateString()], [['product_id' => $product->id, 'qty' => 5, 'unit_cost' => 1]], $this->user);

        $service->cancel($draft, $this->user);

        expect($draft->fresh()->status)->toBe(DocumentStatus::Cancelled)
            ->and(StockMovement::count())->toBe(0)
            ->and(fn () => $service->post($draft, $this->user))->toThrow(ValidationException::class);
    });
});

describe('adjustments', function () {
    beforeEach(function () {
        $this->product = stockProduct();
        receive([['product_id' => $this->product->id, 'qty' => 10, 'unit_cost' => 50]], $this->user);
        $this->adjustments = app(AdjustmentService::class);
    });

    it('requires remarks', function () {
        expect(fn () => $this->adjustments->createDraft(
            ['date' => today()->toDateString(), 'reason' => AdjustmentReason::Damaged, 'remarks' => ' '],
            [['product_id' => $this->product->id, 'qty' => -1]],
            $this->user,
        ))->toThrow(ValidationException::class, 'Remarks are required');
    });

    it('enforces the direction of each reason', function (AdjustmentReason $reason, float $qty, bool $ok) {
        $create = fn () => $this->adjustments->createDraft(
            ['date' => today()->toDateString(), 'reason' => $reason, 'remarks' => 'test'],
            [['product_id' => $this->product->id, 'qty' => $qty]],
            $this->user,
        );

        $ok ? expect($create())->not->toBeNull() : expect($create)->toThrow(ValidationException::class);
    })->with([
        'damaged out' => [AdjustmentReason::Damaged, -1, true],
        'damaged in' => [AdjustmentReason::Damaged, 1, false],
        'lost in' => [AdjustmentReason::Lost, 2, false],
        'internal use out' => [AdjustmentReason::InternalUse, -1, true],
        'found in' => [AdjustmentReason::Found, 1, true],
        'found out' => [AdjustmentReason::Found, -1, false],
        'correction out' => [AdjustmentReason::Correction, -1, true],
        'correction in' => [AdjustmentReason::Correction, 1, true],
    ]);

    it('posts in/out movements at average cost and audit-logs them', function () {
        $draft = $this->adjustments->createDraft(
            ['date' => today()->toDateString(), 'reason' => AdjustmentReason::Damaged, 'remarks' => 'Dropped, cracked housing'],
            [['product_id' => $this->product->id, 'qty' => -2]],
            $this->user,
        );

        $this->adjustments->post($draft, $this->user);

        $movement = $draft->movements()->first();

        expect((float) $this->product->fresh()->qty_on_hand)->toBe(8.0)
            ->and($movement->type)->toBe(MovementType::AdjustmentOut)
            ->and((float) $movement->unit_cost)->toBe(50.0)
            ->and($movement->remarks)->toContain('Dropped, cracked housing')
            ->and((float) $draft->items()->first()->unit_cost)->toBe(50.0);

        $log = Activity::where('event', 'posted')->where('subject_type', $draft->getMorphClass())->first();
        expect($log->causer_id)->toBe($this->user->id)
            ->and($log->properties['remarks'])->toBe('Dropped, cracked housing');
    });

    it('cannot remove more than is on hand', function () {
        $draft = $this->adjustments->createDraft(
            ['date' => today()->toDateString(), 'reason' => AdjustmentReason::Lost, 'remarks' => 'Missing'],
            [['product_id' => $this->product->id, 'qty' => -11]],
            $this->user,
        );

        expect(fn () => $this->adjustments->post($draft, $this->user))->toThrow(ValidationException::class);
        expect($draft->fresh()->status)->toBe(DocumentStatus::Draft)
            ->and((float) $this->product->fresh()->qty_on_hand)->toBe(10.0);
    });
});

describe('physical count', function () {
    it('opens a session for a category with a snapshot of system qty', function () {
        $brakes = Category::create(['name' => 'Brakes']);
        $pad = stockProduct(['name' => 'Brake Pad', 'category_id' => $brakes->id]);
        stockProduct(['name' => 'Spark Plug']); // other category
        receive([['product_id' => $pad->id, 'qty' => 6, 'unit_cost' => 180]], $this->user);

        $count = app(PhysicalCountService::class)->open(['date' => today()->toDateString(), 'category_id' => $brakes->id], $this->user);

        expect($count->items)->toHaveCount(1)
            ->and((float) $count->items->first()->system_qty)->toBe(6.0)
            ->and($count->items->first()->counted_qty)->toBeNull();
    });

    it('posts count_variance movements for counted lines only, against live qty', function () {
        $a = stockProduct(['name' => 'A']);
        $b = stockProduct(['name' => 'B']);
        $c = stockProduct(['name' => 'C']);
        receive([
            ['product_id' => $a->id, 'qty' => 10, 'unit_cost' => 10],
            ['product_id' => $b->id, 'qty' => 5, 'unit_cost' => 20],
            ['product_id' => $c->id, 'qty' => 3, 'unit_cost' => 30],
        ], $this->user);

        $counts = app(PhysicalCountService::class);
        $count = $counts->open(['date' => today()->toDateString()], $this->user);

        // A sale happens while the count is open.
        DB::transaction(fn () => app(StockService::class)->move($a, MovementType::Sale, -1));

        $items = $count->items()->get()->keyBy('product_id');
        $counts->recordCount($items[$a->id], 8);   // live 9 → variance −1
        $counts->recordCount($items[$b->id], 5);   // matches → no movement
        // C not counted → untouched

        $counts->post($count, $this->user);

        $movements = $count->movements()->get();

        expect($movements)->toHaveCount(1)
            ->and($movements->first()->type)->toBe(MovementType::CountVariance)
            ->and((float) $movements->first()->qty)->toBe(-1.0)
            ->and((float) $a->fresh()->qty_on_hand)->toBe(8.0)
            ->and((float) $b->fresh()->qty_on_hand)->toBe(5.0)
            ->and((float) $c->fresh()->qty_on_hand)->toBe(3.0)
            ->and($count->fresh()->status)->toBe(DocumentStatus::Posted);

        expect(fn () => $counts->recordCount($items[$c->id]->fresh(), 1))->toThrow(ValidationException::class);
    });

    it('needs at least one counted line', function () {
        stockProduct();
        $counts = app(PhysicalCountService::class);
        $count = $counts->open(['date' => today()->toDateString()], $this->user);

        expect(fn () => $counts->post($count, $this->user))->toThrow(ValidationException::class);
    });
});

describe('defective stock', function () {
    it('moves a shelf item out of sellable stock into the defective bucket', function () {
        $product = stockProduct();
        receive([['product_id' => $product->id, 'qty' => 4, 'unit_cost' => 75]], $this->user);

        $defective = app(DefectiveStockService::class)->fromStock($product, 1, 'Cracked casing', $this->user);

        expect((float) $product->fresh()->qty_on_hand)->toBe(3.0)
            ->and($defective->status)->toBe(DefectiveStatus::Pending)
            ->and((float) $defective->unit_cost)->toBe(75.0)
            ->and(StockMovement::where('type', MovementType::DefectiveOut)->sum('qty'))->toEqual(-1);
    });

    it('records returned defective items without touching sellable stock', function () {
        $product = stockProduct();

        app(DefectiveStockService::class)->fromReturn($product, 1, 75, null, 'Customer return');

        expect((float) $product->fresh()->qty_on_hand)->toBe(0.0)
            ->and(StockMovement::count())->toBe(0);
    });

    it('returns to supplier or writes off exactly once', function () {
        $product = stockProduct();
        $service = app(DefectiveStockService::class);
        $supplier = Supplier::create(['name' => 'Motorstar']);

        $one = $service->fromReturn($product, 1, 75, null);
        $two = $service->fromReturn($product, 2, 75, null);

        $service->returnToSupplier($one, $supplier->id, 'RMA-55', $this->user);
        $service->writeOff($two, $this->user, 'Beyond repair');

        expect($one->fresh())->status->toBe(DefectiveStatus::ReturnedToSupplier)->supplier_reference->toBe('RMA-55')
            ->and($two->fresh())->status->toBe(DefectiveStatus::WrittenOff)->resolved_by->toBe($this->user->id)
            ->and(fn () => $service->writeOff($one->fresh(), $this->user))->toThrow(ValidationException::class);
    });
});
