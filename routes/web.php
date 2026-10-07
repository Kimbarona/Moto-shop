<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Front of house (React + Inertia). Back-office lives in Filament at /admin.
*/
Route::get('/', fn (Request $request) => $request->user()
    ? redirect($request->user()->role->homePath())
    : redirect()->route('login'))->name('home');

Route::middleware(['auth', 'password.changed'])->group(function () {
    // Screens arrive in Phase 4; placeholders keep role landing pages working.
    Route::inertia('pos', 'coming-soon', ['title' => 'POS'])->middleware('can:use-pos')->name('pos');
    Route::inertia('service-board', 'coming-soon', ['title' => 'Service Board'])->middleware('can:use-service-board')->name('service-board');
    Route::inertia('stock-lookup', 'coming-soon', ['title' => 'Stock Lookup'])->middleware('can:view-stock')->name('stock-lookup');
});

require __DIR__.'/settings.php';
