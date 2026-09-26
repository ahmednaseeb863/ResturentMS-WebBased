<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $job->title }}</title>
        @vite(['resources/css/print/receipt.css'])
    </head>
    {{-- Kitchen ticket (KOT) / void slip for the browser print dialog (PLAN §8). QZ Tray gets the same slip as ESC/POS. --}}
    <body class="print-page paper-{{ $paper }}">
        <div class="print-toolbar no-print">
            <span><strong>{{ $job->title }}</strong> — {{ $job->printer->name }}</span>
            <button type="button" onclick="window.print()">Print</button>
        </div>

        <main class="receipt kot">
            <div class="kot-heading">{{ $slip['heading'] }}</div>
            @if ($slip['void'])
                <div class="receipt-center">{{ $slip['kot'] }}</div>
            @endif
            @if ($slip['station'])
                <div class="receipt-center receipt-strong">{{ mb_strtoupper($slip['station']) }}</div>
            @endif
            @if ($slip['reprint'])
                <div class="receipt-center">(REPRINT)</div>
            @endif

            <div class="receipt-rule"></div>
            <div class="kot-order">{{ collect([$slip['order'], $slip['table']])->filter()->join('  ·  ') }}</div>
            <dl class="receipt-lines">
                <dt class="receipt-normal">{{ $slip['type'] }}@if ($slip['guests']) · {{ $slip['guests'] }} guests @endif</dt>
                <dd>{{ $slip['time'] }}</dd>
                @if ($slip['waiter'])
                    <dt class="receipt-normal">Waiter</dt><dd>{{ $slip['waiter'] }}</dd>
                @endif
                @if ($slip['customer'])
                    <dt class="receipt-normal">Customer</dt><dd>{{ $slip['customer'] }}</dd>
                @endif
            </dl>
            <div class="receipt-rule"></div>

            @foreach ($slip['lines'] as $line)
                <div @class(['kot-line', 'kot-voided' => $line['voided']])>
                    <div class="kot-item"><span class="kot-qty">{{ $line['quantity'] }} ×</span> {{ $line['name'] }}@if ($line['voided']) (VOID)@endif</div>
                    @if ($line['deal'])
                        <div class="kot-sub">({{ $line['deal'] }})</div>
                    @endif
                    @foreach ($line['extras'] as $extra)
                        <div class="kot-sub">+ {{ $extra }}</div>
                    @endforeach
                    @if ($line['note'])
                        <div class="kot-note">** {{ $line['note'] }}</div>
                    @endif
                </div>
            @endforeach

            <div class="receipt-rule"></div>
            @if ($slip['reason'])
                <div class="receipt-strong">Reason: {{ $slip['reason'] }}</div>
            @endif
            @if ($slip['by'])
                <div>Voided by: {{ $slip['by'] }}</div>
            @endif
            @if ($slip['notes'])
                <div>Note: {{ $slip['notes'] }}</div>
            @endif
        </main>

        @if ($auto)
            <script>
                // Opened by a screen's print agent in a hidden frame: print, then tell it.
                window.addEventListener('afterprint', function () {
                    window.parent.postMessage({ type: 'print-job-done', id: @json($job->uuid) }, window.location.origin);
                });
                window.addEventListener('load', function () { window.print(); });
            </script>
        @endif
    </body>
</html>
