<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PrinterConnection;
use App\Enums\PrinterType;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\PrinterRequest;
use App\Http\Resources\PrinterResource;
use App\Models\Printer;
use App\Support\Activity;
use App\Support\CurrentBranch;
use App\Support\Settings\SettingsResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Thermal printers of the current branch. */
class PrinterController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'printers.restore');
        $search = trim((string) $request->query('search'));
        $type = (string) $request->query('type', '');

        $printers = $this->applyTab(Printer::query(), $tab)
            ->with('counters')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('device_name', 'like', "%{$search}%")
                ->orWhere('ip_address', 'like', "%{$search}%")))
            ->when(PrinterType::tryFrom($type), fn ($q, $t) => $q->where('type', $t))
            ->orderBy('type')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('printers/Index', [
            'printers' => PrinterResource::collection($printers),
            'filters' => ['tab' => $tab, 'search' => $search, 'type' => $type],
            'counts' => $this->tabCounts(Printer::class),
            'types' => PrinterType::options(),
            'connections' => PrinterConnection::options(),
            'printMethod' => app(SettingsResolver::class)->get('printing.method'),
        ]);
    }

    public function store(PrinterRequest $request): RedirectResponse
    {
        $printer = Printer::create($request->printerData());

        return back()->with('success', "Printer “{$printer->name}” added.");
    }

    public function update(PrinterRequest $request, Printer $printer): RedirectResponse
    {
        $printer->update($request->printerData());

        return back()->with('success', "Printer “{$printer->name}” saved.");
    }

    /**
     * Test slip at the printer's paper width, printed by the browser (opened in a new
     * window, which calls window.print()). Kitchen tickets go through the print queue
     * (App\Support\Printing\PrintQueue), silently through QZ Tray when set.
     */
    public function test(Printer $printer, CurrentBranch $current, SettingsResolver $settings): View
    {
        $printer->forceFill(['last_tested_at' => now()])->save();
        Activity::log('test_print', $printer);

        return view('print.test', [
            'printer' => $printer,
            'branch' => $current->get(),
            'businessName' => $settings->get('general.business_name'),
            'header' => $settings->get('receipt.header_text'),
            'footer' => $settings->get('receipt.footer_text'),
            'logo' => $settings->get('receipt.show_logo') ? $settings->url('general.logo') : null,
            'method' => $settings->get('printing.method'),
        ]);
    }

    public function destroy(Request $request, Printer $printer): RedirectResponse
    {
        $printer->trash($this->trashReason($request));

        return back()->with('success', "Printer “{$printer->name}” moved to trash.");
    }

    public function restore(Printer $printer): RedirectResponse
    {
        $printer->restoreFromTrash();

        return back()->with('success', "Printer “{$printer->name}” restored.");
    }
}
