<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }}</title>
        @vite(['resources/css/print/receipt.css'])
    </head>
    {{-- Customer bill (pre-bill) / receipt for the browser print dialog (PLAN §4.13). QZ Tray gets the same slip as ESC/POS. --}}
    <body class="print-page paper-{{ $paper }}">
        <div class="print-toolbar no-print">
            <span><strong>{{ $title }}</strong>@if ($printer) — {{ $printer }}@endif</span>
            <button type="button" onclick="window.print()">Print</button>
        </div>

        <main class="receipt">
            @if ($slip['logo'])
                <img class="receipt-logo" src="{{ $slip['logo'] }}" alt="">
            @endif
            <div class="receipt-title">{{ $slip['business'] }}</div>
            @foreach (array_filter([$slip['branch'], $slip['address'], $slip['phone'] ? 'Tel: '.$slip['phone'] : null, $slip['ntn'] ? 'NTN: '.$slip['ntn'] : null]) as $line)
                <div class="receipt-center">{{ $line }}</div>
            @endforeach
            @if ($slip['header'])
                <div class="receipt-center receipt-pre">{{ $slip['header'] }}</div>
            @endif

            <div class="receipt-rule"></div>
            <div class="receipt-center receipt-strong">{{ $slip['heading'] }}@if ($slip['reprint']) (COPY)@endif</div>
            <div class="bill-order">{{ $slip['order'] }}</div>
            <dl class="receipt-lines">
                @foreach ($slip['info'] as [$label, $value])
                    <dt class="receipt-normal">{{ $label }}</dt><dd>{{ $value }}</dd>
                @endforeach
            </dl>
            <div class="receipt-rule"></div>

            @if ($slip['split'])
                <div class="receipt-strong">{{ $slip['split']['label'] }} of {{ $slip['split']['of'] }}@if ($slip['split']['by_items']) — own items @endif</div>
            @endif
            <table class="receipt-table">
                @foreach ($slip['lines'] as $line)
                    <tr>
                        <td>
                            {{ $line['quantity'] }} × {{ $line['name'] }}
                            @foreach ($line['extras'] as $extra)
                                <div class="receipt-sub">{{ $extra }}</div>
                            @endforeach
                            @if ($line['discount'])
                                <div class="receipt-sub">Discount {{ $line['discount'] }}</div>
                            @endif
                        </td>
                        <td>{{ $line['amount'] }}</td>
                    </tr>
                @endforeach
            </table>
            <div class="receipt-rule"></div>

            <dl class="receipt-lines">
                @foreach ($slip['totals'] as [$label, $amount, $strong])
                    @if ($strong)
                        <dt class="receipt-total bill-grand">{{ $label }}</dt><dd class="receipt-total bill-grand">{{ $amount }}</dd>
                    @else
                        <dt class="receipt-normal">{{ $label }}</dt><dd>{{ $amount }}</dd>
                    @endif
                @endforeach
                @if ($slip['split'])
                    <dt class="receipt-total">{{ $slip['split']['label'] }} pays</dt><dd class="receipt-total">{{ $slip['split']['amount'] }}</dd>
                @endif
            </dl>

            @if ($slip['payments'])
                <div class="receipt-rule"></div>
                <dl class="receipt-lines">
                    @foreach ($slip['payments'] as $i => [$label, $amount])
                        <dt @class(['receipt-normal' => $i < count($slip['payments']) - 1])>{{ $label }}</dt><dd>{{ $amount }}</dd>
                    @endforeach
                </dl>
            @endif

            @if ($slip['banks'])
                <div class="receipt-rule"></div>
                <div class="receipt-heading">Pay by bank transfer</div>
                @foreach ($slip['banks'] as $bank)
                    <div class="bill-bank">
                        <div class="receipt-strong">{{ $bank['bank'] }} — {{ $bank['title'] }}</div>
                        <div>{{ $bank['number'] }}</div>
                        @if ($bank['iban'])
                            <div class="receipt-sub">{{ $bank['iban'] }}</div>
                        @endif
                    </div>
                @endforeach
            @endif

            <div class="receipt-rule"></div>
            <div class="receipt-center receipt-sub">Printed {{ $slip['time'] }}</div>
            @if ($slip['footer'])
                <div class="receipt-center receipt-pre">{{ $slip['footer'] }}</div>
            @endif
        </main>

        @if ($auto)
            <script>
                // Opened in a hidden frame (print agent / print button): print, then tell the page.
                window.addEventListener('afterprint', function () {
                    window.parent.postMessage({ type: 'print-job-done', id: @json($doneId) }, window.location.origin);
                });
                window.addEventListener('load', function () { window.print(); });
            </script>
        @endif
    </body>
</html>
