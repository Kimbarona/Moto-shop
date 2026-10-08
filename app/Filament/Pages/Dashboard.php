<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Back-office dashboard. The period filter (Today / This Week / This Month / Custom)
 * drives every sales widget; stock widgets always show the current position.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    ToggleButtons::make('period')
                        ->hiddenLabel()
                        ->options([
                            'today' => 'Today',
                            'week' => 'This Week',
                            'month' => 'This Month',
                            'custom' => 'Custom',
                        ])
                        ->default('today')
                        ->inline()
                        ->live(),
                    DatePicker::make('from')
                        ->default(now()->startOfMonth())
                        ->maxDate(now())
                        ->visible(fn (Get $get) => $get('period') === 'custom'),
                    DatePicker::make('until')
                        ->default(now())
                        ->maxDate(now())
                        ->visible(fn (Get $get) => $get('period') === 'custom'),
                ])
                ->columns(['md' => 3])
                ->columnSpanFull()
                ->visible(fn () => auth()->user()?->can('view-reports')),
        ]);
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 6];
    }
}
