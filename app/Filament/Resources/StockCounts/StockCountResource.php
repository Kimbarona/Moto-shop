<?php

namespace App\Filament\Resources\StockCounts;

use App\Enums\DocumentStatus;
use App\Filament\Actions\InventoryDocumentActions;
use App\Filament\Resources\StockCounts\Pages\CreateStockCount;
use App\Filament\Resources\StockCounts\Pages\EditStockCount;
use App\Filament\Resources\StockCounts\Pages\ListStockCounts;
use App\Filament\Resources\StockCounts\Pages\ViewStockCount;
use App\Filament\Resources\StockCounts\RelationManagers\CountItemsRelationManager;
use App\Models\StockCount;
use App\Services\Stock\PhysicalCountService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Physical count: open a session → type counted qty per part → review variance → post.
 */
class StockCountResource extends Resource
{
    protected static ?string $model = StockCount::class;

    protected static ?string $modelLabel = 'Physical Count';

    protected static ?string $slug = 'physical-counts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Physical Count';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'count_no';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->description(fn (string $operation) => $operation === 'create'
                        ? 'Opening the count lists every active part in scope. Type the counted quantities on the next screen.'
                        : 'Type counted quantities below. Parts left blank are not changed. Post as soon as counting is finished — variance is measured against live stock.')
                    ->schema([
                        DatePicker::make('date')->required()->default(now())->maxDate(now()),
                        Select::make('category_id')->label('Scope')
                            ->relationship('category', 'name')
                            ->placeholder('All products')
                            ->disabledOn('edit'),
                        Textarea::make('notes')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'creator'])
                ->withCount(['items', 'items as counted_count' => fn (Builder $q) => $q->whereNotNull('counted_qty')]))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('count_no')->label('No.')->searchable()->weight('bold'),
                TextColumn::make('date')->date('M d, Y')->sortable(),
                TextColumn::make('category.name')->label('Scope')->placeholder('All products'),
                TextColumn::make('progress')->label('Counted')
                    ->state(fn (StockCount $record) => "{$record->counted_count} / {$record->items_count}"),
                TextColumn::make('status')->badge(),
                TextColumn::make('creator.name')->label('Opened by'),
                TextColumn::make('posted_at')->label('Posted')->dateTime('M d, Y h:i A')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(DocumentStatus::options()),
            ])
            ->recordActions([
                EditAction::make()->label('Count')->visible(fn (StockCount $record) => auth()->user()->can('update', $record)),
                ViewAction::make()->visible(fn (StockCount $record) => ! $record->isDraft()),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            CountItemsRelationManager::class,
        ];
    }

    public static function postAction(): Action
    {
        return InventoryDocumentActions::post(
            fn (StockCount $record) => app(PhysicalCountService::class)->post($record, auth()->user()),
            function (StockCount $record) {
                $counted = $record->items()->whereNotNull('counted_qty')->count();
                $total = $record->items()->count();

                return "Apply the variance for {$counted} counted part(s)? ".($total - $counted).' part(s) left blank will not change.';
            },
        );
    }

    public static function cancelAction(): Action
    {
        return InventoryDocumentActions::cancel(fn (StockCount $record) => app(PhysicalCountService::class)->cancel($record, auth()->user()));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockCounts::route('/'),
            'create' => CreateStockCount::route('/create'),
            'view' => ViewStockCount::route('/{record}'),
            'edit' => EditStockCount::route('/{record}/edit'),
        ];
    }
}
