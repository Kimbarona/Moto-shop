<?php

namespace App\Filament\Resources\Services;

use App\Enums\CommissionType;
use App\Filament\Resources\Services\Pages\ManageServices;
use App\Models\Service;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static ?string $modelLabel = 'Service (Labor)';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Masterlist';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')->required()->maxLength(20)->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (string $state) => strtoupper(trim($state))),
                TextInput::make('name')->required()->maxLength(150),
                TextInput::make('labor_fee')->numeric()->prefix('₱')->minValue(0)->required(),
                TextInput::make('est_minutes')->label('Est. minutes')->integer()->minValue(0),
                Select::make('commission_type')->options(CommissionType::options())->placeholder('Use default % from Settings')->live(),
                TextInput::make('commission_value')->numeric()->minValue(0)
                    ->visible(fn ($get) => in_array($get('commission_type'), ['percent', 'fixed'], true))
                    ->suffix(fn ($get) => $get('commission_type') === 'percent' ? '%' : '₱')
                    ->required(fn ($get) => in_array($get('commission_type'), ['percent', 'fixed'], true)),
                Toggle::make('is_active')->default(true)->inline(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->badge()->color('primary')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('labor_fee')->money('PHP')->alignEnd()->sortable(),
                TextColumn::make('est_minutes')->label('Est. min')->alignEnd(),
                TextColumn::make('commission')->state(fn ($record) => match ($record->commission_type) {
                    CommissionType::Percent => rtrim(rtrim((string) $record->commission_value, '0'), '.').'%',
                    CommissionType::Fixed => Money::format($record->commission_value),
                    CommissionType::None => 'None',
                    default => 'Default %',
                }),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->label('Archive'),
                RestoreAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageServices::route('/'),
        ];
    }
}
