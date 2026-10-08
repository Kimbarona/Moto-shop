<x-filament-panels::page>
    @if (! $current)
        {{-- Report picker --}}
        <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($groups as $group => $reports)
                <x-filament::section :heading="$group">
                    <ul class="-my-2 divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($reports as $class)
                            <li>
                                <button type="button" wire:click="choose('{{ $class::key() }}')"
                                    class="group flex w-full items-start gap-3 py-2 text-left">
                                    <x-filament::icon icon="heroicon-o-document-chart-bar" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400 group-hover:text-primary-500" />
                                    <span>
                                        <span class="block font-semibold text-gray-950 group-hover:text-primary-600 dark:text-white">{{ $class::title() }}</span>
                                        <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $class::description() }}</span>
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endforeach
        </div>
    @else
        {{-- Filters --}}
        <x-filament::section>
            <div class="flex flex-wrap items-end gap-4">
                <x-filament::link tag="button" wire:click="$set('report', null)" icon="heroicon-m-arrow-left" class="self-center">
                    All reports
                </x-filament::link>

                @if ($current::usesDates())
                    <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-300">
                        From
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" wire:model.live="from" />
                        </x-filament::input.wrapper>
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-300">
                        Until
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" wire:model.live="until" />
                        </x-filament::input.wrapper>
                    </label>
                    <div class="flex flex-wrap gap-1">
                        @foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'last_month' => 'Last month', 'year' => 'This year'] as $key => $label)
                            <x-filament::button size="xs" color="gray" outlined wire:click="preset('{{ $key }}')">{{ $label }}</x-filament::button>
                        @endforeach
                    </div>
                @endif

                @foreach ($fields as $name => $field)
                    <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ $field['label'] }}
                        <x-filament::input.wrapper>
                            @if ($field['type'] === 'select')
                                <x-filament::input.select wire:model.live="opts.{{ $name }}">
                                    @foreach ($field['options'] as $value => $optionLabel)
                                        <option value="{{ $value }}" @selected(($opts[$name] ?? $field['default']) == $value)>{{ $optionLabel }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            @else
                                <x-filament::input type="number" min="1" wire:model.live.debounce.500ms="opts.{{ $name }}" placeholder="{{ $field['default'] }}" />
                            @endif
                        </x-filament::input.wrapper>
                    </label>
                @endforeach

                <div class="ms-auto flex gap-2">
                    <x-filament::button icon="heroicon-m-table-cells" color="success" wire:click="exportExcel">Excel</x-filament::button>
                    <x-filament::button icon="heroicon-m-document-arrow-down" color="danger" wire:click="exportPdf">PDF</x-filament::button>
                </div>
            </div>
        </x-filament::section>

        <div wire:loading.class="opacity-50" class="space-y-6 transition-opacity">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $current::description() }} <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $result->period }}</span>
            </p>

            @if ($result->summary)
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-7">
                    @foreach ($result->summary as $label => $figure)
                        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</div>
                            <div class="fi-wi-stats-overview-stat-value mt-1 text-2xl text-gray-950 dark:text-white">{{ $result->formatFigure($figure) }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            @foreach ($result->sections as $section)
                <x-filament::section :heading="$section->title" compact>
                    @if ($section->rows === [])
                        <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">Nothing in this period.</p>
                    @else
                        <div class="-mx-4 overflow-x-auto">
                            <table class="w-full text-sm" data-report-table>
                                <thead>
                                    <tr class="border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                                        @foreach ($section->columns as $column)
                                            <th class="whitespace-nowrap px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300 {{ $column->isNumeric() ? 'text-end' : 'text-start' }}">{{ $column->label }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                    @foreach ($section->rows as $row)
                                        <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                            @foreach ($section->columns as $column)
                                                @php $value = $row[$column->key] ?? null; @endphp
                                                <td @class([
                                                    'px-4 py-2 align-top',
                                                    'fi-ta-text text-end whitespace-nowrap' => $column->isNumeric(),
                                                    'whitespace-nowrap' => in_array($column->type, ['date', 'datetime'], true),
                                                    'text-danger-600 dark:text-danger-400' => $column->isNumeric() && (float) $value < 0,
                                                    'font-semibold' => $column->type === 'text' && is_string($value) && preg_match('/^[A-Z][A-Z :]{3,}$/', $value) === 1,
                                                ])>{{ $column->format($value) }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                                @if ($totals = $section->totals())
                                    <tfoot>
                                        <tr class="border-t-2 border-gray-300 font-bold dark:border-white/20">
                                            @foreach ($section->columns as $i => $column)
                                                <td class="whitespace-nowrap px-4 py-2 {{ $column->isNumeric() ? 'fi-ta-text text-end' : '' }}">
                                                    {{ $i === 0 ? 'Total' : (array_key_exists($column->key, $totals) ? $column->format($totals[$column->key]) : '') }}
                                                </td>
                                            @endforeach
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                        </div>
                    @endif
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
