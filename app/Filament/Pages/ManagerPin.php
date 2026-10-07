<?php

namespace App\Filament\Pages;

use App\Services\Approvals\ManagerPinService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * Admins set their own 4–6 digit manager PIN (stored hashed).
 */
class ManagerPin extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'My Manager PIN';

    protected static ?string $slug = 'manager-pin';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description(fn () => auth()->user()->hasPin()
                        ? 'You already have a PIN. Enter a new one to replace it.'
                        : 'Cashiers ask for this PIN to approve big discounts, price overrides, voids and refunds.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('current_password')->label('Your password')->password()->required()->currentPassword(),
                        TextInput::make('pin')->label('New PIN (4–6 digits)')->password()->revealable()->required()
                            ->regex('/^\d{4,6}$/')->confirmed(),
                        TextInput::make('pin_confirmation')->label('Confirm PIN')->password()->required(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save PIN')->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        try {
            app(ManagerPinService::class)->setPin(auth()->user(), $state['pin']);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['data.pin' => $e->errors()['pin'] ?? $e->getMessage()]);
        }

        $this->form->fill();

        Notification::make()->success()->title('Manager PIN saved')->send();
    }
}
