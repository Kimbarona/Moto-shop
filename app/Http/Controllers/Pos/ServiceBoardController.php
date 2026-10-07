<?php

namespace App\Http\Controllers\Pos;

use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\PosCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kanban of Pending service orders: Waiting → In Progress → Done (Ready for Payment).
 * Mechanics see only the jobs assigned to them.
 */
class ServiceBoardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $jobs = Order::query()
            ->pending()
            ->where('type', OrderType::Service)
            ->with(['customer', 'motorcycle.motorcycleModel.make', 'mechanics'])
            ->withCount('items')
            ->when($user->isMechanic(), fn ($q) => $q->whereHas('mechanics', fn ($m) => $m->where('user_id', $user->id)))
            ->oldest()
            ->get()
            ->map(fn (Order $order) => OrderController::card($order));

        return Inertia::render('service-board/index', [
            'jobs' => $jobs,
            'mechanics' => PosCatalog::props($user)['mechanics'],
        ]);
    }
}
