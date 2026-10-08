@php
    $record = $getRecord();
    $rows = \App\Support\AuditLog::changes($record);
    $hasBefore = collect($rows)->contains(fn (array $row) => $row['old'] !== null);
@endphp

@if ($rows === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">No details recorded for this entry.</p>
@else
    <div class="-mx-4 overflow-x-auto">
        <table class="w-full text-sm" data-audit-changes>
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                    <th class="px-4 py-2 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Field</th>
                    @if ($hasBefore)
                        <th class="px-4 py-2 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Before</th>
                    @endif
                    <th class="px-4 py-2 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ $hasBefore ? 'After' : 'Value' }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($rows as $row)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-2 align-top font-medium text-gray-700 dark:text-gray-200">{{ $row['field'] }}</td>
                        @if ($hasBefore)
                            <td class="px-4 py-2 align-top break-all tabular-nums text-danger-600 line-through decoration-danger-600/40 dark:text-danger-400">{{ $row['old'] }}</td>
                        @endif
                        <td @class([
                            'px-4 py-2 align-top break-all tabular-nums text-gray-950 dark:text-white',
                            'font-semibold text-success-700 dark:text-success-400' => $hasBefore && $row['old'] !== null,
                        ])>{{ $row['new'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
