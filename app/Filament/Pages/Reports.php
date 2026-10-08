<?php

namespace App\Filament\Pages;

use App\Exports\ReportExport;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Settings\ShopSettings;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;

/**
 * Every report on one page: pick a report, a date range (and its options), read it on
 * screen, export the same result to Excel or PDF. The URL keeps the choice, so a report
 * can be bookmarked or shared with another admin.
 */
class Reports extends Page
{
    protected string $view = 'filament.pages.reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 1;

    #[Url]
    public ?string $report = null;

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    /**
     * @var array<string, mixed>
     */
    #[Url]
    public array $opts = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('view-reports') || $user->can('view-inventory-reports'));
    }

    public function mount(): void
    {
        $this->from ??= today()->startOfMonth()->toDateString();
        $this->until ??= today()->toDateString();

        if ($this->report && ! $this->reportClass()) {
            $this->report = null;
        }
    }

    public function getTitle(): string|Htmlable
    {
        $class = $this->reportClass();

        return $class ? $class::title() : 'Reports';
    }

    public function choose(string $key): void
    {
        $this->report = $key;
        $this->opts = [];
    }

    public function preset(string $preset): void
    {
        $today = CarbonImmutable::today();

        [$from, $until] = match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'week' => [$today->startOfWeek(), $today],
            'month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            default => [$today->startOfYear(), $today],
        };

        $this->from = $from->toDateString();
        $this->until = $until->toDateString();
    }

    /**
     * @return class-string<Report>|null only reports this user may open
     */
    public function reportClass(): ?string
    {
        $class = ReportRegistry::find($this->report);

        return $class && $class::canBeViewedBy(auth()->user()) ? $class : null;
    }

    public function period(): Period
    {
        return new Period($this->from ?: today(), $this->until ?: $this->from ?: today());
    }

    public function result(): ?ReportResult
    {
        $class = $this->reportClass();

        return $class ? (new $class)->withOptions($this->opts)->run($this->period(), auth()->user()) : null;
    }

    public function exportExcel(): ?Response
    {
        $result = $this->result();

        if (! $result) {
            return null;
        }

        $this->logExport('excel');

        return Excel::download(new ReportExport($result), $this->fileName('xlsx'));
    }

    public function exportPdf(): ?Response
    {
        $result = $this->result();

        if (! $result) {
            return null;
        }

        $this->logExport('pdf');

        $pdf = Pdf::loadView('reports.pdf', [
            'result' => $result,
            'shop' => rescue(fn () => app(ShopSettings::class)->shop_name, config('app.name'), report: false),
            'user' => auth()->user()->name,
        ])->setPaper('a4', 'landscape')->setOption('isFontSubsettingEnabled', true);

        return response()->streamDownload(fn () => print ($pdf->output()), $this->fileName('pdf'), ['Content-Type' => 'application/pdf']);
    }

    private function fileName(string $extension): string
    {
        $period = $this->period();

        return sprintf('%s_%s_%s.%s', $this->report, $period->from->format('Ymd'), $period->until->format('Ymd'), $extension);
    }

    private function logExport(string $format): void
    {
        activity('Report')->causedBy(auth()->user())->event('exported')
            ->withProperties(['report' => $this->report, 'format' => $format, 'from' => $this->from, 'until' => $this->until, 'options' => $this->opts])
            ->log('Exported '.$this->getTitle().' ('.strtoupper($format).')');
    }

    protected function getViewData(): array
    {
        $class = $this->reportClass();

        return [
            'groups' => ReportRegistry::grouped(auth()->user()),
            'current' => $class,
            'result' => $this->result(),
            'fields' => $class ? $class::optionFields() : [],
        ];
    }
}
