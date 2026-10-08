<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\StockCardRelationManager;
use App\Models\MotorcycleModel;
use App\Models\Product;
use App\Settings\InventorySettings;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Parts masterlist. qty_on_hand is never edited here: stock only changes through
 * Stock In, Adjustments, Physical Count, sales and returns (StockService).
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $modelLabel = 'Part';

    protected static ?string $pluralModelLabel = 'Products (Parts)';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $canEditPricing = fn () => auth()->user()?->can('edit-pricing') ?? false;
        $canViewCost = fn () => auth()->user()?->can('view-cost') ?? false;

        return $schema
            ->columns(3)
            ->components([
                Section::make('Part details')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),
                        TextInput::make('sku')->label('SKU')
                            ->maxLength(30)
                            ->unique(ignoreRecord: true)
                            ->placeholder('Auto (e.g. P-000123)')
                            ->helperText('Leave blank to assign the next number.'),
                        TextInput::make('part_number')->label('Part no.')->maxLength(100),
                        Select::make('brand_id')->relationship('brand', 'name')->searchable()->preload()
                            ->createOptionForm([TextInput::make('name')->required()->maxLength(100)]),
                        Select::make('category_id')->relationship('category', 'name')->searchable()->preload(),
                        Select::make('unit_id')->relationship('unit', 'name')->required()->preload()
                            ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->name} ({$record->abbreviation})"),
                        TextInput::make('bin_location')->label('Bin / shelf')->maxLength(50),
                        Textarea::make('description')->rows(2)->columnSpanFull(),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Pricing')
                            ->schema([
                                TextInput::make('srp')->label('SRP')->numeric()->prefix('₱')->minValue(0)->default(0)->required()
                                    ->live(onBlur: true)
                                    ->disabled(fn () => ! $canEditPricing())
                                    ->dehydrated($canEditPricing),
                                TextInput::make('avg_cost')->label('Average cost')->numeric()->prefix('₱')->minValue(0)->default(0)
                                    ->helperText('Updated automatically by Stock In (weighted average). Edit only to correct it.')
                                    ->live(onBlur: true)
                                    ->visible($canViewCost)
                                    ->disabled(fn () => ! $canEditPricing())
                                    ->dehydrated($canEditPricing),
                                TextEntry::make('margin')->label('Margin')
                                    ->visible($canViewCost)
                                    ->state(fn (Get $get) => static::marginLabel((float) $get('srp'), (float) $get('avg_cost'))),
                            ]),
                        Section::make('Stock')
                            ->schema([
                                TextEntry::make('qty_on_hand')->label('On hand')
                                    ->state(fn (?Product $record) => $record ? static::stockSummary($record) : 'Use Stock In to add stock')
                                    ->hiddenOn('create'),
                                TextInput::make('reorder_level')->numeric()->minValue(0)->required()
                                    ->default(fn () => app(InventorySettings::class)->default_reorder_level),
                                FileUpload::make('image')->image()->disk('public')->directory('products')->imageEditor()->maxSize(2048),
                                Toggle::make('is_active')->label('Active (sellable)')->default(true),
                            ]),
                    ]),

                Section::make('Fitment')
                    ->description('Which motorcycles this part fits. Universal parts show for every model in the POS "Fits motorcycle" filter.')
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('is_universal')->label('Universal (fits all models)')->live(),
                        Repeater::make('fitments')
                            ->relationship()
                            ->hidden(fn (Get $get) => (bool) $get('is_universal'))
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add motorcycle model')
                            ->schema([
                                Select::make('motorcycle_model_id')->label('Model')
                                    ->options(fn () => static::modelOptions())
                                    ->searchable()
                                    ->required()
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                TextInput::make('notes')->maxLength(255)->placeholder('e.g. front only, 2018+'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $canViewCost = fn () => auth()->user()?->can('view-cost') ?? false;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['brand', 'category', 'unit'])->withStockLevels())
            ->defaultSort('name')
            ->columns([
                TextColumn::make('sku')->label('SKU')->badge()->color('gray')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable()->weight('bold')->wrap()
                    ->description(fn (Product $record) => collect([$record->part_number, $record->brand?->name])->filter()->implode(' · ')),
                TextColumn::make('category.name')->label('Category')->sortable()->toggleable(),
                TextColumn::make('qty_on_hand')->label('On hand')->numeric(2)->alignEnd()->sortable()
                    ->suffix(fn (Product $record) => ' '.$record->unit?->abbreviation),
                TextColumn::make('reserved_qty')->label('Reserved')->numeric(2)->alignEnd()
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('available_qty')->label('Available')->numeric(2)->alignEnd()->sortable()
                    ->badge()
                    ->color(fn (Product $record) => match (true) {
                        (float) $record->available_qty <= 0 => 'danger',
                        (float) $record->available_qty <= (float) $record->reorder_level => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('avg_cost')->label('Avg cost')->money('PHP')->alignEnd()->sortable()->visible($canViewCost),
                TextColumn::make('srp')->label('SRP')->money('PHP')->alignEnd()->sortable(),
                TextColumn::make('margin')->label('Margin')->alignEnd()->visible($canViewCost)
                    ->state(fn (Product $record) => static::marginLabel((float) $record->srp, (float) $record->avg_cost)),
                TextColumn::make('bin_location')->label('Bin')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_universal')->label('Universal')->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Active')->boolean()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category_id')->label('Category')->relationship('category', 'name')->preload(),
                SelectFilter::make('brand_id')->label('Brand')->relationship('brand', 'name')->preload()->searchable(),
                SelectFilter::make('fits')->label('Fits motorcycle')
                    ->options(fn () => static::modelOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => filled($data['value']) ? $query->fitsModel((int) $data['value']) : $query),
                Filter::make('low_stock')->label('Low stock (at or below reorder level)')->query(fn (Builder $query) => $query->lowStock()),
                TernaryFilter::make('is_active')->label('Active'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Edit'),
                DeleteAction::make()->label('Archive')->iconButton()->icon(Heroicon::OutlinedArchiveBox)->tooltip('Archive'),
                RestoreAction::make()->iconButton()->tooltip('Restore'),
            ]);
    }

    /**
     * Search-as-you-type part picker used by inventory documents.
     */
    public static function productSelect(string $name = 'product_id'): Select
    {
        return Select::make($name)
            ->label('Part')
            ->searchable()
            ->required()
            ->getSearchResultsUsing(fn (string $search) => Product::query()->active()->search($search)
                ->with('unit')->orderBy('name')->limit(50)->get()
                ->mapWithKeys(fn (Product $product) => [$product->id => static::productLabel($product)]))
            ->getOptionLabelUsing(fn ($value) => ($product = Product::withTrashed()->with('unit')->find($value))
                ? static::productLabel($product)
                : null);
    }

    public static function productLabel(Product $product): string
    {
        return sprintf('%s · %s (on hand %s %s)', $product->sku, $product->name, rtrim(rtrim(number_format((float) $product->qty_on_hand, 2), '0'), '.'), $product->unit?->abbreviation);
    }

    public static function marginLabel(float $srp, float $cost): string
    {
        if ($srp <= 0) {
            return '—';
        }

        return number_format(($srp - $cost) / $srp * 100, 1).'% ('.Money::format($srp - $cost).')';
    }

    public static function stockSummary(Product $product): string
    {
        $unit = $product->unit?->abbreviation;
        $reserved = $product->reservedQty();

        return sprintf(
            '%s %s on hand · %s reserved · %s available',
            number_format((float) $product->qty_on_hand, 2),
            $unit,
            number_format($reserved, 2),
            number_format((float) $product->qty_on_hand - $reserved, 2),
        );
    }

    /**
     * Models grouped by make for selects.
     *
     * @return array<string, array<int, string>>
     */
    public static function modelOptions(): array
    {
        return MotorcycleModel::query()
            ->with('make')
            ->get()
            ->sortBy(fn (MotorcycleModel $model) => $model->make?->name.' '.$model->name)
            ->groupBy(fn (MotorcycleModel $model) => $model->make?->name ?? 'Other')
            ->map(fn ($models) => $models->pluck('name', 'id')->all())
            ->all();
    }

    public static function getRelations(): array
    {
        return [
            StockCardRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
