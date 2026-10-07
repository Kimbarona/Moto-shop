<?php

namespace App\Filament\Resources\Mechanics;

use App\Enums\Role;
use App\Filament\Resources\Mechanics\Pages\ManageMechanics;
use App\Models\Mechanic;
use App\Models\User;
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

class MechanicResource extends Resource
{
    protected static ?string $model = Mechanic::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Masterlist';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')->required()->maxLength(100),
                TextInput::make('mobile')->tel()->maxLength(30),
                Select::make('user_id')->label('Login account (optional)')
                    ->helperText('Link a Mechanic user so they can update their own jobs on the Service Board.')
                    ->options(fn () => User::query()->where('role', Role::Mechanic)->orderBy('name')->pluck('name', 'id'))
                    ->unique(ignoreRecord: true)
                    ->searchable(),
                Toggle::make('is_active')->default(true)->inline(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('mobile'),
                TextColumn::make('user.email')->label('Login')->placeholder('No login'),
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
            'index' => ManageMechanics::route('/'),
        ];
    }
}
