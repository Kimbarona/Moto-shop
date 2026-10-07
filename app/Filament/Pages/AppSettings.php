<?php

namespace App\Filament\Pages;

use App\Enums\PaperSize;
use App\Models\PaymentMethod;
use App\Services\Numbering\SequenceService;
use App\Settings\AppearanceSettings;
use App\Settings\InventorySettings;
use App\Settings\PosSettings;
use App\Settings\SecuritySettings;
use App\Settings\ServiceSettings;
use App\Settings\ShopSettings;
use App\Settings\SlipSettings;
use App\Settings\TaxSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelSettings\Settings;
use UnitEnum;

/**
 * App Settings. Super admins edit; admins can view. Every change is audit-logged with
 * before/after values. Document number prefixes and counters live in the sequences
 * table (counters can only move forward).
 */
class AppSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'App Settings';

    protected static ?string $title = 'App Settings';

    protected static ?string $slug = 'settings';

    /**
     * Form section key => settings class. Field names are "<key>.<property>".
     *
     * @var array<string, class-string<Settings>>
     */
    private const SETTINGS = [
        'shop' => ShopSettings::class,
        'slip' => SlipSettings::class,
        'tax' => TaxSettings::class,
        'pos' => PosSettings::class,
        'inventory' => InventorySettings::class,
        'service' => ServiceSettings::class,
        'security' => SecuritySettings::class,
        'appearance' => AppearanceSettings::class,
    ];

    /**
     * Sequence keys editable on this page.
     */
    private const SEQUENCES = ['order', 'slip', 'return', 'sku'];

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-settings') ?? false;
    }

    public function canEdit(): bool
    {
        return auth()->user()?->can('manage-settings') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill($this->currentValues());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->disabled(fn () => ! $this->canEdit())
            ->components([
                Tabs::make('settings')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Shop Profile')->icon(Heroicon::OutlinedBuildingStorefront)->columns(2)->schema([
                            TextInput::make('shop.shop_name')->label('Shop name')->required()->maxLength(120),
                            FileUpload::make('shop.logo')->label('Logo')->image()->disk('public')->directory('branding')->maxSize(2048)
                                ->helperText('Shown in the sidebar, login page and slips.'),
                            Textarea::make('shop.address')->label('Address')->rows(2)->columnSpanFull(),
                            TextInput::make('shop.mobile')->label('Mobile')->tel()->maxLength(30),
                            TextInput::make('shop.email')->label('Email')->email()->maxLength(120),
                            TextInput::make('shop.tin')->label('TIN (optional)')->maxLength(30),
                            TextInput::make('shop.business_hours')->label('Business hours')->maxLength(120),
                        ]),

                        Tab::make('Receipt / Slip')->icon(Heroicon::OutlinedReceiptPercent)->columns(2)->schema([
                            TextInput::make('slip.document_title')->label('Document title')->required()->maxLength(60),
                            Select::make('slip.paper_size')->label('Paper size')->options(PaperSize::options())->required(),
                            TextInput::make('seq.slip.prefix')->label('Slip no. prefix')->maxLength(20),
                            TextInput::make('seq.slip.next_number')->label('Next slip number')->integer()->required()
                                ->minValue(fn () => app(SequenceService::class)->nextNumber('slip'))
                                ->helperText(fn () => 'Can only move forward. Last used: '.app(SequenceService::class)->lastUsedNumber('slip').'.'),
                            Textarea::make('slip.header_text')->label('Header text')->rows(2),
                            Textarea::make('slip.footer_text')->label('Footer text')->rows(2)->required(),
                            Toggle::make('slip.show_logo')->label('Show logo on slips'),
                            TextInput::make('slip.copies')->label('Copies to print')->integer()->minValue(1)->maxValue(3)->required(),
                        ]),

                        Tab::make('Tax')->icon(Heroicon::OutlinedCalculator)->columns(2)->schema([
                            Toggle::make('tax.vat_registered')->label('VAT registered')->live()->columnSpanFull(),
                            TextInput::make('tax.vat_rate')->label('VAT rate')->numeric()->suffix('%')->minValue(0)->maxValue(100)
                                ->visible(fn (Get $get) => (bool) $get('tax.vat_registered')),
                            Toggle::make('tax.prices_vat_inclusive')->label('Prices are VAT-inclusive')
                                ->visible(fn (Get $get) => (bool) $get('tax.vat_registered')),
                        ]),

                        Tab::make('POS')->icon(Heroicon::OutlinedComputerDesktop)->columns(2)->schema([
                            TextInput::make('pos.cashier_max_discount_percent')->label('Cashier max discount without PIN')
                                ->numeric()->suffix('%')->minValue(0)->maxValue(100)->required(),
                            Select::make('pos.default_payment_method_id')->label('Default payment method')
                                ->options(fn () => PaymentMethod::active()->orderBy('sort_order')->pluck('name', 'id')),
                            Toggle::make('pos.require_reference_for_non_cash')->label('Require reference no. for e-wallet / bank'),
                            Toggle::make('pos.allow_price_override')->label('Allow price override'),
                            Toggle::make('pos.allow_walk_in_without_customer')->label('Allow walk-in sale without a customer'),
                        ]),

                        Tab::make('Inventory')->icon(Heroicon::OutlinedCube)->columns(2)->schema([
                            Toggle::make('inventory.allow_negative_stock')->label('Allow negative stock')
                                ->helperText('Off: a sale or reservation can never take more than is available.')->columnSpanFull(),
                            TextInput::make('inventory.default_reorder_level')->label('Default reorder level')->numeric()->minValue(0)->required(),
                            TextInput::make('inventory.dead_stock_days')->label('Dead-stock threshold')->integer()->suffix('days')->minValue(1)->required(),
                            TextInput::make('seq.sku.prefix')->label('SKU prefix')->maxLength(20),
                        ]),

                        Tab::make('Service')->icon(Heroicon::OutlinedWrenchScrewdriver)->columns(2)->schema([
                            TextInput::make('service.default_commission_percent')->label('Default labor commission')
                                ->numeric()->suffix('%')->minValue(0)->maxValue(100)->required(),
                            Toggle::make('service.show_job_ticket_on_create')->label('Print job ticket when a service order is created'),
                            TextInput::make('service.pms_interval_km')->label('PMS interval')->integer()->suffix('km')->minValue(0),
                            TextInput::make('service.pms_interval_months')->label('PMS interval')->integer()->suffix('months')->minValue(0),
                        ]),

                        Tab::make('Order Numbering')->icon(Heroicon::OutlinedHashtag)->columns(3)->schema([
                            TextInput::make('seq.order.prefix')->label('Order no. prefix')->maxLength(20)
                                ->helperText(fn () => 'Next: '.app(SequenceService::class)->peek('order')),
                            TextEntry::make('slip_note')->label('Slip no.')
                                ->state(fn () => 'Next: '.app(SequenceService::class)->peek('slip').' (prefix and counter are under Receipt / Slip)'),
                            TextInput::make('seq.return.prefix')->label('Return no. prefix')->maxLength(20)
                                ->helperText(fn () => 'Next: '.app(SequenceService::class)->peek('return')),
                        ]),

                        Tab::make('Security')->icon(Heroicon::OutlinedShieldCheck)->columns(3)->schema([
                            TextInput::make('security.session_timeout_minutes')->label('Session timeout')->integer()->suffix('min')->minValue(5)->required(),
                            TextInput::make('security.password_min_length')->label('Password minimum length')->integer()->minValue(6)->maxValue(64)->required(),
                            TextInput::make('security.max_pin_attempts')->label('Max manager PIN attempts')->integer()->minValue(1)->maxValue(20)->required(),
                        ]),

                        Tab::make('Appearance')->icon(Heroicon::OutlinedSwatch)->schema([
                            Select::make('appearance.pos_theme')->label('POS default theme')
                                ->options(['dark' => 'Dark', 'light' => 'Light', 'system' => 'System default'])->required(),
                        ]),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->visible(fn () => ! $this->canEdit())
                ->description('View only. Only a Super Admin can change App Settings.'),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save settings')->submit('save')->visible(fn () => $this->canEdit()),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);

        $state = $this->form->getState();
        $before = $this->flatten($this->currentValues());
        $sequences = app(SequenceService::class);

        try {
            DB::transaction(function () use ($state, $sequences) {
                foreach (self::SETTINGS as $key => $class) {
                    /** @var Settings $settings */
                    $settings = app($class);
                    $settings->fill($state[$key] ?? [])->save();
                }

                foreach (self::SEQUENCES as $key) {
                    if (array_key_exists('prefix', $state['seq'][$key] ?? [])) {
                        $sequences->setPrefix($key, (string) $state['seq'][$key]['prefix']);
                    }
                }

                $sequences->setNextNumber('slip', (int) $state['seq']['slip']['next_number']);
            });
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['data.seq.slip.next_number' => $e->errors()['next_number'] ?? $e->getMessage()]);
        }

        $after = $this->flatten($this->currentValues());
        $changed = array_keys(array_diff_assoc(array_map('json_encode', $after), array_map('json_encode', $before)));

        if ($changed !== []) {
            activity('Settings')
                ->causedBy(auth()->user())
                ->event('updated')
                ->withProperties([
                    'old' => array_intersect_key($before, array_flip($changed)),
                    'attributes' => array_intersect_key($after, array_flip($changed)),
                ])
                ->log('App settings updated');
        }

        $this->form->fill($this->currentValues());

        Notification::make()->success()->title('Settings saved')->send();
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        $values = [];

        foreach (self::SETTINGS as $key => $class) {
            $values[$key] = app($class)->refresh()->toArray();
        }

        $sequences = app(SequenceService::class);

        foreach (self::SEQUENCES as $key) {
            $values['seq'][$key] = ['prefix' => $sequences->prefix($key), 'next_number' => $sequences->nextNumber($key)];
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix.$key.'.');
            } else {
                $flat[$prefix.$key] = $value;
            }
        }

        return $flat;
    }
}
