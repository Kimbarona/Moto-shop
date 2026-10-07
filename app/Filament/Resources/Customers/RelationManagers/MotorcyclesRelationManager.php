<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MotorcyclesRelationManager extends RelationManager
{
    protected static string $relationship = 'motorcycles';

    protected static ?string $title = 'Motorcycles';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('motorcycle_model_id')->label('Model')
                    ->options(fn () => ProductResource::modelOptions())
                    ->searchable()
                    ->required(),
                TextInput::make('plate_no')->label('Plate no.')
                    ->maxLength(20)
                    ->helperText('Leave blank for a brand-new bike without plates.')
                    ->dehydrateStateUsing(fn (?string $state) => filled($state) ? strtoupper(trim($state)) : null)
                    ->unique(ignoreRecord: true), // DB index also covers archived bikes
                TextInput::make('year')->integer()->minValue(1970)->maxValue(2100),
                TextInput::make('color')->maxLength(50),
                TextInput::make('last_odometer')->label('Last odometer (km)')->integer()->minValue(0),
                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('plate_no')
            ->modifyQueryUsing(fn ($query) => $query->with('motorcycleModel.make'))
            ->columns([
                TextColumn::make('plate_no')->label('Plate')->badge()->color('primary')->placeholder('No plate'),
                TextColumn::make('motorcycleModel.full_name')->label('Model'),
                TextColumn::make('year'),
                TextColumn::make('color'),
                TextColumn::make('last_odometer')->label('Odometer')->numeric()->suffix(' km')->alignEnd(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->label('Archive'),
            ]);
    }
}
