<?php

namespace App\Filament\Resources\MotorcycleModels;

use App\Filament\Resources\MotorcycleModels\Pages\ManageMotorcycleModels;
use App\Models\MotorcycleMake;
use App\Models\MotorcycleModel;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class MotorcycleModelResource extends Resource
{
    protected static ?string $model = MotorcycleModel::class;

    protected static ?string $modelLabel = 'Motorcycle Model';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Masterlist';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('motorcycle_make_id')->label('Make')->relationship('make', 'name')->required()->preload()->searchable(),
                TextInput::make('name')->required()->maxLength(100),
                TextInput::make('engine_cc')->label('Engine (cc)')->integer()->minValue(50)->maxValue(2000),
                TextInput::make('year_from')->integer()->minValue(1970)->maxValue(2100),
                TextInput::make('year_to')->integer()->minValue(1970)->maxValue(2100)->gte('year_from'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('make.name')->label('Make')->sortable()->searchable(),
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('engine_cc')->label('cc')->alignEnd()->sortable(),
                TextColumn::make('year_from')->label('Years')->formatStateUsing(fn ($record) => trim($record->year_from.'–'.($record->year_to ?? 'present'), '–')),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('motorcycle_make_id')->label('Make')->options(fn () => MotorcycleMake::orderBy('name')->pluck('name', 'id')),
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
            'index' => ManageMotorcycleModels::route('/'),
        ];
    }
}
