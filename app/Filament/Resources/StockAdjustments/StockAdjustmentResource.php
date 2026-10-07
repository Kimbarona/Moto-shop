<?php

namespace App\Filament\Resources\StockAdjustments;

use App\Enums\AdjustmentReason;
use App\Enums\DocumentStatus;
use App\Filament\Actions\InventoryDocumentActions;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\EditStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Filament\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Models\StockAdjustment;
use App\Services\Stock\AdjustmentService;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StockAdjustmentResource extends Resource
{
    protected static ?string $model = StockAdjustment::class;

    protected static ?string $modelLabel = 'Stock Adjustment';

    protected static ?string $slug = 'adjustments';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Adjustments';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'adjustment_no';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        DatePicker::make('date')->required()->default(now())->maxDate(now()),
                        Select::make('reason')->options(AdjustmentReason::options())->required()->live()
                            ->helperText(fn (Get $get) => match (filled($get('reason')) ? AdjustmentService::allowedSign(AdjustmentReason::from($get('reason'))) : 0) {
                                -1 => 'Removes stock: enter negative quantities (e.g. -2).',
                                1 => 'Adds stock: enter positive quantities.',
                                null => 'Positive adds stock, negative removes it.',
                                default => null,
                            }),
                        Textarea::make('remarks')->required()->rows(2)->columnSpanFull()
                            ->helperText('Required. Explain what happened — this goes to the audit log.'),
                    ]),
                Section::make('Parts')
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->hiddenLabel()
                            ->columns(4)
                            ->minItems(1)
                            ->addActionLabel('Add part')
                            ->schema([
                                ProductResource::productSelect()->columnSpan(3)->distinct(),
                                TextInput::make('qty')->label('Qty (+ in / − out)')->numeric()->required()
                                    ->rule(fn (Get $get) => static::directionRule($get('../../reason'))),
                            ]),
                    ]),
            ]);
    }

    public static function directionRule(?string $reason): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($reason) {
            $qty = (float) $value;

            if ($qty == 0.0) {
                $fail('Quantity cannot be zero.');

                return;
            }

            $sign = filled($reason) ? AdjustmentService::allowedSign(AdjustmentReason::from($reason)) : null;

            if ($sign !== null && $qty * $sign < 0) {
                $fail($sign < 0 ? 'This reason only removes stock: use a negative qty.' : 'This reason only adds stock: use a positive qty.');
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['creator'])->withCount('items'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('adjustment_no')->label('No.')->searchable()->weight('bold'),
                TextColumn::make('date')->date('M d, Y')->sortable(),
                TextColumn::make('reason')->badge(),
                TextColumn::make('remarks')->limit(50)->wrap()->searchable(),
                TextColumn::make('items_count')->label('Lines')->alignEnd(),
                TextColumn::make('status')->badge(),
                TextColumn::make('creator.name')->label('Encoded by'),
                TextColumn::make('posted_at')->label('Posted')->dateTime('M d, Y h:i A')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(DocumentStatus::options()),
                SelectFilter::make('reason')->options(AdjustmentReason::options()),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (StockAdjustment $record) => auth()->user()->can('update', $record)),
                ViewAction::make()->visible(fn (StockAdjustment $record) => ! $record->isDraft()),
                static::postAction(),
            ]);
    }

    public static function postAction(): Action
    {
        return InventoryDocumentActions::post(
            fn (StockAdjustment $record) => app(AdjustmentService::class)->post($record, auth()->user()),
            function (StockAdjustment $record) {
                $warnings = app(AdjustmentService::class)->reservationWarnings($record);

                return $warnings === []
                    ? 'Stock will be updated now and the adjustment logged. Posted adjustments can no longer be edited.'
                    : "Warning:\n".implode("\n", $warnings)."\n\nPost anyway?";
            },
        );
    }

    public static function cancelAction(): Action
    {
        return InventoryDocumentActions::cancel(fn (StockAdjustment $record) => app(AdjustmentService::class)->cancel($record, auth()->user()));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockAdjustments::route('/'),
            'create' => CreateStockAdjustment::route('/create'),
            'view' => ViewStockAdjustment::route('/{record}'),
            'edit' => EditStockAdjustment::route('/{record}/edit'),
        ];
    }
}
