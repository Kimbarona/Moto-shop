<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCardResource;
use App\Models\Category;
use App\Models\MotorcycleMake;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    /**
     * Product search for the POS finder and stock lookup.
     * Filters: q (name / SKU / part no. / brand), category_id, model_id (fits + universal).
     */
    public function products(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'model_id' => ['nullable', 'integer'],
            'with_fits' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = Product::query()
            ->active()
            ->with(['brand', 'category', 'unit'])
            ->when($request->boolean('with_fits'), fn ($q) => $q->with('fitsModels.make'))
            ->withStockLevels()
            ->search($request->string('q')->toString())
            ->when($request->integer('category_id'), fn ($q, $id) => $q->where('products.category_id', $id))
            ->when($request->integer('model_id'), fn ($q, $id) => $q->fitsModel($id))
            ->orderBy('products.name')
            ->simplePaginate(40);

        return response()->json([
            'data' => ProductCardResource::collection($page->getCollection())->resolve($request),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function stockLookup(): Response
    {
        return Inertia::render('stock-lookup', [
            'categories' => Category::orderBy('sort_order')->get(['id', 'name', 'icon']),
            'makes' => MotorcycleMake::with(['models' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get()
                ->map(fn ($make) => [
                    'id' => $make->id,
                    'name' => $make->name,
                    'models' => $make->models->map(fn ($model) => ['id' => $model->id, 'name' => $model->name])->values(),
                ]),
        ]);
    }
}
