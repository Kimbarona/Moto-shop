<?php

use App\Http\Controllers\Pos\CatalogController;
use App\Http\Controllers\Pos\CustomerController;
use App\Http\Controllers\Pos\OrderActionController;
use App\Http\Controllers\Pos\OrderController;
use App\Http\Controllers\Pos\OrderItemController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\PrintController;
use App\Http\Controllers\Pos\ServiceBoardController;
use App\Http\Controllers\Pos\SummaryController;
use App\Support\HomeRedirect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Front of house (React + Inertia). Back-office lives in Filament at /admin.
| Route middleware gives the coarse role check; policies in the services decide the rest.
*/
Route::get('/', fn (Request $request) => $request->user()
    ? HomeRedirect::home($request, $request->user())
    : redirect()->route('login'))->name('home');

Route::middleware(['auth', 'password.changed'])->group(function () {
    // Screens
    Route::get('pos', [PosController::class, 'index'])->middleware('can:use-pos')->name('pos');
    Route::get('jobs/{order}', [PosController::class, 'job'])->middleware('can:use-service-board')->name('jobs.show');
    Route::get('service-board', ServiceBoardController::class)->middleware('can:use-service-board')->name('service-board');
    Route::get('orders/open', [OrderController::class, 'open'])->middleware('can:use-service-board')->name('orders.open');
    Route::get('orders/{order}', [OrderController::class, 'page'])->middleware('can:use-service-board')->name('orders.show');
    Route::get('stock-lookup', [CatalogController::class, 'stockLookup'])->middleware('can:view-stock')->name('stock-lookup');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('can:use-service-board')->name('customers.show');
    Route::get('my-summary', SummaryController::class)->name('my-summary');

    // Printing (browser print)
    Route::middleware('can:use-service-board')->prefix('print')->name('print.')->group(function () {
        Route::get('slip/{order}', [PrintController::class, 'slip'])->name('slip');
        Route::get('job-ticket/{order}', [PrintController::class, 'jobTicket'])->name('job-ticket');
    });

    // JSON API used by the POS / Service Board
    Route::prefix('pos/api')->name('pos.api.')->group(function () {
        Route::get('products', [CatalogController::class, 'products'])->middleware('can:view-stock')->name('products');

        Route::middleware('can:use-service-board')->group(function () {
            Route::get('customers', [CustomerController::class, 'search'])->name('customers.search');
            Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
            Route::post('customers/{customer}/motorcycles', [CustomerController::class, 'storeMotorcycle'])->name('customers.motorcycles.store');

            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::patch('orders/{order}', [OrderController::class, 'update'])->name('orders.update');
            Route::put('orders/{order}/service-status', [OrderActionController::class, 'serviceStatus'])->name('orders.service-status');

            Route::scopeBindings()->group(function () {
                Route::post('orders/{order}/items', [OrderItemController::class, 'store'])->name('orders.items.store');
                Route::patch('orders/{order}/items/{item}', [OrderItemController::class, 'update'])->name('orders.items.update');
                Route::delete('orders/{order}/items/{item}', [OrderItemController::class, 'destroy'])->name('orders.items.destroy');
            });
        });

        Route::middleware('can:use-pos')->group(function () {
            Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
            Route::put('orders/{order}/discount', [OrderActionController::class, 'discount'])->name('orders.discount');
            Route::post('orders/{order}/void', [OrderActionController::class, 'void'])->name('orders.void');
        });

        Route::middleware('can:take-payment')->group(function () {
            Route::post('orders/{order}/pay', [OrderActionController::class, 'pay'])->name('orders.pay');
            Route::post('checkout', [OrderActionController::class, 'checkout'])->name('checkout');
        });
    });
});

require __DIR__.'/settings.php';
