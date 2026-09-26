@php
    $fmt = function ($value, string $type) use ($branchId) {
        if ($value === null || $value === '') {
            return '';
        }
        return match ($type) {
            'money' => money($value, $branchId),
            'qty' => rtrim(rtrim(number_format((float) $value, 3), '0'), '.'),
            'int' => number_format((float) $value),
            'percent' => number_format((float) $value, 1).'%',
            'date' => \Carbon\Carbon::parse($value)->format('j M Y'),
            default => $value,
        };
    };
    $numeric = ['money', 'qty', 'int', 'percent'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $report->title() }}</title>
    <style>
        @page { margin: 28px 30px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1c1c1e; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .sub { color: #6b6b70; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: .04em; color: #555; border-bottom: 1.5px solid #1c1c1e; padding: 5px 4px; }
        td { padding: 4px; border-bottom: 1px solid #e2e2e5; }
        .num { text-align: right; white-space: nowrap; }
        tr.strong td, tr.total td { font-weight: bold; }
        tr.total td { border-top: 1.5px solid #1c1c1e; border-bottom: 0; }
        .empty { padding: 20px; text-align: center; color: #6b6b70; }
        .foot { position: fixed; bottom: -14px; left: 0; right: 0; font-size: 7px; color: #999; }
    </style>
</head>
<body>
    <h1>{{ $report->title() }}</h1>
    <div class="sub">
        {{ $filters->branchLabel() }} · {{ $report->usesDates() ? $filters->periodLabel() : 'as of '.now(setting('general.timezone', $branchId))->format('j M Y H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($report->columns() as $column)
                    <th class="{{ in_array($column['type'], $numeric, true) ? 'num' : '' }}">{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="{{ ! empty($row['strong']) ? 'strong' : '' }}">
                    @foreach ($report->columns() as $column)
                        <td class="{{ in_array($column['type'], $numeric, true) ? 'num' : '' }}">{{ $fmt($row[$column['key']] ?? null, $column['type']) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td class="empty" colspan="{{ count($report->columns()) }}">Nothing in this period.</td></tr>
            @endforelse
            @if ($totals)
                <tr class="total">
                    @foreach ($report->columns() as $i => $column)
                        <td class="{{ in_array($column['type'], $numeric, true) ? 'num' : '' }}">{{ $i === 0 ? 'Total' : $fmt($totals[$column['key']] ?? null, $column['type']) }}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>

    <div class="foot">Printed {{ now(setting('general.timezone', $branchId))->format('j M Y H:i') }}</div>
</body>
</html>
