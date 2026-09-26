<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $kind }}-Report — {{ $shift->code() }}</title>
        @vite(['resources/css/print/receipt.css'])
    </head>
    {{-- X-report (shift still open, nothing changes) / Z-report (closed shift) at the counter's paper width. --}}
    <body class="print-page paper-{{ $paper }}">
        <div class="print-toolbar no-print">
            <span>
                <strong>{{ $kind }}-Report</strong> of shift {{ $shift->code() }} —
                {{ $kind === 'X' ? 'a mid-shift reading, the shift stays open.' : 'final report of the closed shift.' }}
                Use “Save as PDF” in the print dialog for a PDF.
            </span>
            <button type="button" onclick="window.print()">Print</button>
        </div>

        <main class="receipt">
            <div class="receipt-title">{{ $businessName }}</div>
            @if ($branch)
                <div class="receipt-center">{{ $branch->name }}</div>
            @endif

            <div class="receipt-rule"></div>
            <div class="receipt-center receipt-strong">*** {{ $kind }}-REPORT ***</div>
            @if ($kind === 'X')
                <div class="receipt-center receipt-sub">Shift still open</div>
            @endif
            <div class="receipt-rule"></div>

            <dl class="receipt-lines">
                <dt>Shift</dt><dd>{{ $shift->code() }}</dd>
                <dt>Counter</dt><dd>{{ $shift->counter?->name }}</dd>
                @if ($shift->type)
                    <dt>Type</dt><dd>{{ $shift->type->name }} {{ $shift->type->startsAt() }}–{{ $shift->type->endsAt() }}</dd>
                @endif
                <dt>Business day</dt><dd>{{ $shift->business_date->format('d M Y') }}</dd>
                <dt>Cashier</dt><dd>{{ $shift->openedBy?->name }}</dd>
                <dt>Opened</dt><dd>{{ $shift->opened_at->timezone(setting('general.timezone'))->format('d M, h:i A') }}</dd>
                @if ($shift->closed_at)
                    <dt>Closed</dt><dd>{{ $shift->closed_at->timezone(setting('general.timezone'))->format('d M, h:i A') }}</dd>
                    <dt>Closed by</dt><dd>{{ $shift->closedBy?->name }}</dd>
                @endif
                <dt>Printed</dt><dd>{{ now()->timezone(setting('general.timezone'))->format('d M, h:i A') }}</dd>
            </dl>

            <div class="receipt-rule"></div>
            <div class="receipt-heading">Cash drawer</div>
            <dl class="receipt-lines">
                @foreach ($lines as $line)
                    <dt class="receipt-normal">{{ $line['label'] }}@if ($line['count'] > 1) ({{ $line['count'] }})@endif</dt>
                    <dd>{{ $line['key'] === 'opening' || $line['amount'] == 0 ? '' : ($line['amount'] < 0 ? '−' : '+') }}{{ money(abs($line['amount'])) }}</dd>
                @endforeach
                <dt class="receipt-total">Expected cash</dt><dd class="receipt-total">{{ money($expected) }}</dd>
                @unless ($shift->isOpen())
                    <dt>Counted cash</dt><dd>{{ money($shift->counted_cash) }}</dd>
                    <dt>{{ $shift->difference > 0 ? 'Over' : ($shift->difference < 0 ? 'Short' : 'Difference') }}</dt>
                    <dd>{{ (float) $shift->difference === 0.0 ? 'Balanced' : money(abs($shift->difference)) }}</dd>
                    <dt class="receipt-normal">Float left</dt><dd>{{ money($shift->float_left) }}</dd>
                    <dt class="receipt-normal">Handed over</dt><dd>{{ money($shift->handed_over_amount) }}</dd>
                    @if ($shift->approvedBy)
                        <dt class="receipt-normal">Approved by</dt><dd>{{ $shift->approvedBy->name }}</dd>
                    @endif
                @endunless
            </dl>

            @if ($counts->isNotEmpty())
                <div class="receipt-rule"></div>
                <div class="receipt-heading">Closing count</div>
                <table class="receipt-table">
                    @foreach ($counts as $count)
                        <tr><td>{{ money($count->denomination) }} × {{ $count->quantity }}</td><td>{{ money($count->amount) }}</td></tr>
                    @endforeach
                </table>
            @endif

            @if ($movements->isNotEmpty())
                <div class="receipt-rule"></div>
                <div class="receipt-heading">Cash in / out</div>
                <table class="receipt-table">
                    @foreach ($movements as $movement)
                        <tr>
                            <td>
                                {{ $movement->created_at->timezone(setting('general.timezone'))->format('h:i A') }} {{ $movement->type->label() }}
                                @if ($movement->reason)<div class="receipt-sub">{{ $movement->reason }}</div>@endif
                            </td>
                            <td>{{ $movement->type->direction() < 0 ? '−' : '+' }}{{ money($movement->amount) }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @if ($staff->isNotEmpty())
                <div class="receipt-rule"></div>
                <div class="receipt-heading">Staff on duty</div>
                <table class="receipt-table">
                    @foreach ($staff as $member)
                        <tr>
                            <td>{{ $member->employee?->name }}</td>
                            <td>
                                {{ $member->checked_in_at->timezone(setting('general.timezone'))->format('h:i A') }}–{{ $member->checked_out_at?->timezone(setting('general.timezone'))->format('h:i A') ?? 'now' }}
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif

            <div class="receipt-rule"></div>
            <div class="receipt-center receipt-sub">*** END OF {{ $kind }}-REPORT ***</div>
        </main>

        <script>
            window.addEventListener('load', function () { window.print(); });
        </script>
    </body>
</html>
