<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Actions\InventoryDocumentActions;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Services\Expenses\ExpenseService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Shop expenses (rent, power, salaries…). Mistakes are voided with a reason, never deleted.
 */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Expenses';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                DatePicker::make('date')->required()->default(now())->maxDate(now()),
                Select::make('expense_category_id')->label('Category')
                    ->options(fn () => ExpenseCategory::orderBy('name')->pluck('name', 'id'))
                    ->searchable()->required()
                    ->createOptionForm([TextInput::make('name')->required()->maxLength(100)])
                    ->createOptionUsing(fn (array $data) => ExpenseCategory::create($data)->getKey()),
                TextInput::make('description')->required()->maxLength(255)->columnSpanFull(),
                TextInput::make('amount')->numeric()->prefix('₱')->minValue(0.01)->maxValue(9999999)->required(),
                Select::make('payment_method_id')->label('Paid by')
                    ->options(fn () => PaymentMethod::active()->orderBy('sort_order')->pluck('name', 'id')),
                TextInput::make('reference_no')->label('Receipt / ref no.')->maxLength(100),
                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'paymentMethod', 'creator', 'voider']))
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')->date('M d, Y')->sortable(),
                TextColumn::make('category.name')->label('Category')->badge()->color('gray'),
                TextColumn::make('description')->searchable()->wrap()
                    ->description(fn (Expense $record) => $record->isVoided() ? 'VOIDED: '.$record->void_reason : $record->notes),
                TextColumn::make('amount')->money('PHP')->alignEnd()->weight('bold')
                    ->summarize(Sum::make()->money('PHP')->label('')->query(fn ($query) => $query->whereNull('voided_at'))),
                TextColumn::make('paymentMethod.name')->label('Paid by')->placeholder('—'),
                TextColumn::make('reference_no')->label('Ref no.')->placeholder('—')->searchable()->toggleable(),
                TextColumn::make('creator.name')->label('Entered by')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordClasses(fn (Expense $record) => $record->isVoided() ? 'opacity-60 line-through' : null)
            ->filters([
                SelectFilter::make('expense_category_id')->label('Category')->relationship('category', 'name'),
                TernaryFilter::make('voided')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('voided_at'),
                        false: fn (Builder $q) => $q->whereNull('voided_at'),
                    )
                    ->default(false),
                Filter::make('date')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '<=', $date))),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('void')
                    ->label('Void')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (Expense $record) => auth()->user()->can('void', $record))
                    ->requiresConfirmation()
                    ->modalDescription('The expense stays on record but is left out of reports.')
                    ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(255)])
                    ->action(fn (Expense $record, array $data, Action $action) => InventoryDocumentActions::run(
                        fn () => app(ExpenseService::class)->void($record, auth()->user(), $data['reason']),
                        $action,
                        'Expense voided',
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpenses::route('/'),
        ];
    }
}
