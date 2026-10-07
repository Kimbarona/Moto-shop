<?php

namespace App\Filament\Resources\Users;

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Services\Users\UserManagementService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/**
 * Users are deactivated, never deleted. Writes go through UserManagementService, which
 * enforces the safety rules (last super admin, no self-demotion, admin scope).
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $isSelf = fn (?User $record) => $record !== null && $record->is(auth()->user());

        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                        Select::make('role')
                            ->options(fn () => collect(app(UserManagementService::class)->assignableRoles(auth()->user()))
                                ->mapWithKeys(fn (Role $role) => [$role->value => $role->getLabel()]))
                            ->required()
                            ->disabled($isSelf)
                            ->helperText(fn (?User $record) => $isSelf($record) ? 'You cannot change your own role.' : null),
                        Toggle::make('is_active')->label('Active (can log in)')->default(true)->inline(false)
                            ->disabled($isSelf),
                        TextInput::make('password')
                            ->password()->revealable()
                            ->required()
                            ->rule(Password::default())
                            ->helperText('The user must change this password on first login.')
                            ->visibleOn('create'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('email')->searchable(),
                TextColumn::make('role')->badge()->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('pin_hash')->label('PIN set')->boolean()
                    ->state(fn (User $record) => $record->isAdmin() ? $record->hasPin() : null),
                IconColumn::make('must_change_password')->label('Temp. password')->boolean()->toggleable(),
                TextColumn::make('last_login_at')->label('Last login')->dateTime('M d, Y h:i A')->sortable()->placeholder('Never'),
            ])
            ->filters([
                SelectFilter::make('role')->options(Role::options()),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('resetPassword')
                    ->label('Reset password')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('warning')
                    ->visible(fn (User $record) => auth()->user()->can('resetPassword', $record))
                    ->schema([
                        TextInput::make('password')->label('New temporary password')
                            ->password()->revealable()->required()->rule(Password::default()),
                    ])
                    ->action(function (User $record, array $data) {
                        app(UserManagementService::class)->resetPassword(auth()->user(), $record, $data['password']);

                        Notification::make()->success()->title('Password reset')
                            ->body("{$record->name} must change it on next login.")->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
