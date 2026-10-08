<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\User;
use App\Support\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * Read-only audit log viewer: who did what, when, from which IP, with before/after
 * values. Entries can't be created, edited or deleted from here (ActivityPolicy).
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $slug = 'audit-log';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit log';

    protected static ?string $navigationLabel = 'Audit Log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Entry')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('created_at')->label('When')->dateTime('M d, Y h:i:s A'),
                        TextEntry::make('causer.name')->label('User')->placeholder('System'),
                        TextEntry::make('log_name')->label('Area')->formatStateUsing(fn (?string $state) => AuditLog::area($state)),
                        TextEntry::make('event')->badge()
                            ->formatStateUsing(fn (?string $state) => AuditLog::eventLabel($state))
                            ->color(fn (?string $state) => AuditLog::eventColor($state)),
                        TextEntry::make('record')->label('Record')
                            ->state(fn (Activity $record) => AuditLog::recordLabel($record) ?: null)
                            ->placeholder('—')
                            ->url(fn (Activity $record) => static::subjectUrl($record))
                            ->color(fn (Activity $record) => static::subjectUrl($record) ? 'primary' : null),
                        TextEntry::make('ip')->label('IP address')
                            ->state(fn (Activity $record) => AuditLog::ip($record))
                            ->placeholder('—'),
                    ]),
                Section::make(fn (Activity $record) => AuditLog::summary($record))
                    ->columnSpan(2)
                    ->schema([
                        ViewEntry::make('changes')
                            ->hiddenLabel()
                            ->view('filament.audit-log.changes'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['causer', 'subject']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('M d, Y h:i A')->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('id', $direction)),
                TextColumn::make('causer.name')->label('User')->placeholder('System'),
                TextColumn::make('log_name')->label('Area')
                    ->formatStateUsing(fn (?string $state) => AuditLog::area($state))
                    ->badge()->color('gray'),
                TextColumn::make('event')->badge()
                    ->formatStateUsing(fn (?string $state) => AuditLog::eventLabel($state))
                    ->color(fn (?string $state) => AuditLog::eventColor($state)),
                TextColumn::make('description')->label('What happened')
                    ->state(fn (Activity $record) => AuditLog::summary($record))
                    ->description(fn (Activity $record) => AuditLog::changeSummary($record))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('ip')->label('IP')
                    ->state(fn (Activity $record) => AuditLog::ip($record))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('causer')->label('User')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $q
                        ->where('causer_type', (new User)->getMorphClass())
                        ->where('causer_id', $id))),
                SelectFilter::make('log_name')->label('Area')
                    ->options(fn () => Activity::query()->distinct()->orderBy('log_name')->pluck('log_name')
                        ->filter()
                        ->mapWithKeys(fn (string $name) => [$name => AuditLog::area($name)])
                        ->sort()
                        ->all()),
                SelectFilter::make('event')
                    ->options(fn () => Activity::query()->distinct()->orderBy('event')->pluck('event')
                        ->filter()
                        ->mapWithKeys(fn (string $event) => [$event => AuditLog::eventLabel($event)])
                        ->sort()
                        ->all()),
                Filter::make('record')
                    ->schema([TextInput::make('id')->label('Record ID')->integer()])
                    ->query(fn (Builder $query, array $data) => $query->when($data['id'] ?? null, fn (Builder $q, $id) => $q->where('subject_id', $id)))
                    ->indicateUsing(fn (array $data) => filled($data['id'] ?? null) ? 'Record #'.$data['id'] : null),
                Filter::make('date')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->filtersFormColumns(2)
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('Nothing logged yet')
            ->emptyStateDescription('Changes, payments, voids, returns and approvals show up here as they happen.');
    }

    /**
     * Back-office page of the record this entry is about, when there is one.
     */
    public static function subjectUrl(Activity $activity): ?string
    {
        if (! $activity->subject_type || ! $activity->subject_id) {
            return null;
        }

        return rescue(function () use ($activity) {
            $resource = Filament::getModelResource($activity->subject_type);

            if (! $resource || $resource === static::class) {
                return null;
            }

            foreach (['view', 'edit'] as $page) {
                if ($resource::hasPage($page)) {
                    return $resource::getUrl($page, ['record' => $activity->subject_id]);
                }
            }

            return null;
        }, null, report: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
