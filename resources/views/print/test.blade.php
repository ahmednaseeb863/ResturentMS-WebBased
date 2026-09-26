<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Test print — {{ $printer->name }}</title>
        @vite(['resources/css/print/receipt.css'])
    </head>
    {{-- Browser-print test slip at the printer's paper width (PLAN §8 "Thermal printing"). --}}
    <body class="print-page paper-{{ $printer->paper_width }}">
        <div class="print-toolbar no-print">
            <span>Test slip for <strong>{{ $printer->name }}</strong> — choose this printer in the print dialog.</span>
            @if ($method === 'qz')
                <span class="print-note">This test uses the browser print dialog; kitchen tickets print silently through QZ Tray.</span>
            @endif
            <button type="button" onclick="window.print()">Print again</button>
        </div>

        <main class="receipt">
            @if ($logo)
                <img class="receipt-logo" src="{{ $logo }}" alt="">
            @endif
            <div class="receipt-title">{{ $businessName }}</div>
            @if ($branch)
                <div class="receipt-center">{{ $branch->name }}</div>
            @endif
            @if ($header)
                <div class="receipt-center receipt-pre">{{ $header }}</div>
            @endif

            <div class="receipt-rule"></div>
            <div class="receipt-center receipt-strong">*** TEST PRINT ***</div>
            <div class="receipt-rule"></div>

            <dl class="receipt-lines">
                <dt>Printer</dt><dd>{{ $printer->name }}</dd>
                <dt>Type</dt><dd>{{ $printer->type->label() }}</dd>
                <dt>Connection</dt><dd>{{ $printer->connection_type->label() }}</dd>
                <dt>Address</dt><dd>{{ $printer->address() ?: '—' }}</dd>
                <dt>Paper</dt><dd>{{ $printer->paper_width }} mm</dd>
                <dt>Printed</dt><dd>{{ now()->format('d M Y, h:i A') }}</dd>
            </dl>

            <div class="receipt-rule"></div>
            <div class="receipt-ruler">1234567890123456789012345678901234567890123456</div>
            <div class="receipt-rule"></div>

            @if ($footer)
                <div class="receipt-center receipt-pre">{{ $footer }}</div>
            @endif
        </main>

        <script>
            window.addEventListener('load', function () { window.print(); });
        </script>
    </body>
</html>
