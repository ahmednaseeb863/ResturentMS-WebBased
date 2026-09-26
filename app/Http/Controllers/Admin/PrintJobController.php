<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PrintDocument;
use App\Enums\PrintJobStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PrintJobResource;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Support\Printing\BillSlip;
use App\Support\Printing\KitchenSlip;
use App\Support\Printing\PrintQueue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Print queue (PLAN §8 "Thermal printing"). A browser in the branch that prints for some
 * printers asks for their pending jobs, claims one (so no other screen prints it too),
 * prints it through QZ Tray (ESC/POS) or the browser dialog (the slip page) and reports
 * printed / failed. The list screen shows the queue and retries.
 */
class PrintJobController extends Controller
{
    public function index(Request $request): Response
    {
        $status = PrintJobStatus::tryFrom((string) $request->query('status', ''));
        $printer = Printer::withTrashed()->where('uuid', (string) $request->query('printer'))->first();

        $jobs = PrintJob::query()
            ->with('printer', 'createdBy', 'printedBy')
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($printer, fn ($q, $p) => $q->where('printer_id', $p->id))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $counts = PrintJob::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('print-jobs/Index', [
            'jobs' => PrintJobResource::collection($jobs),
            'filters' => ['status' => $status?->value ?? '', 'printer' => $printer?->uuid ?? ''],
            'counts' => collect(PrintJobStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
            'statuses' => PrintJobStatus::options(),
            'printers' => Printer::query()->orderBy('name')->get()->map(fn (Printer $p) => ['value' => $p->uuid, 'label' => $p->name, 'type' => $p->type->label()])->all(),
        ]);
    }

    /** Jobs waiting for the printers this device prints for (`?printers[]=uuid`). */
    public function pending(Request $request): JsonResponse
    {
        $printers = array_slice(array_filter((array) $request->query('printers', []), 'is_string'), 0, 20);

        $jobs = PrintJob::query()
            ->where('status', PrintJobStatus::Pending)
            ->whereHas('printer', fn ($q) => $q->whereIn('uuid', $printers))
            ->where('created_at', '>=', now()->subDay())
            ->oldest('id')
            ->limit(20)
            ->get(['uuid', 'title']);

        return response()->json(['jobs' => $jobs->map(fn (PrintJob $j) => ['id' => $j->uuid, 'title' => $j->title])->all()]);
    }

    /** Take a pending job; returns what to print, or 409 when another screen took it first. */
    public function claim(Request $request, PrintJob $job): JsonResponse
    {
        $taken = PrintJob::query()->whereKey($job->id)->where('status', PrintJobStatus::Pending)->update([
            'status' => PrintJobStatus::Printing,
            'attempts' => $job->attempts + 1,
            'claimed_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $taken) {
            return response()->json(['message' => 'Already taken.'], 409);
        }

        $job->refresh()->load('printer', 'reference');
        $printer = $job->printer;

        return response()->json([
            'id' => $job->uuid,
            'title' => $job->title,
            'copies' => $job->copies,
            'printer' => [
                'name' => $printer->name,
                'connection' => $printer->connection_type->value,
                'device' => $printer->device_name,
                'host' => $printer->ip_address,
                'port' => $printer->port ?: Printer::DEFAULT_PORT,
                'paper_width' => $printer->paper_width,
            ],
            'page' => route('print-jobs.show', $job),
            'escpos' => base64_encode($job->document_type->isKitchen()
                ? KitchenSlip::escPos(KitchenSlip::data($job), (int) $printer->paper_width, $job->copies)
                : BillSlip::escPos(BillSlip::forJob($job), (int) $printer->paper_width, $job->copies)),
        ]);
    }

    /** The slip as a print-CSS page for the browser print dialog. */
    public function show(PrintJob $job): View
    {
        $job->load('printer', 'reference');

        if (! $job->document_type->isKitchen()) {
            return view('print.bill', [
                'title' => $job->title,
                'printer' => $job->printer->name,
                'slip' => BillSlip::forJob($job),
                'paper' => $job->printer->paper_width,
                'auto' => request()->boolean('auto'),
                'doneId' => $job->uuid,
            ]);
        }

        return view('print.kitchen', [
            'job' => $job,
            'slip' => KitchenSlip::data($job),
            'paper' => $job->printer->paper_width,
            'auto' => request()->boolean('auto'),
        ]);
    }

    public function done(Request $request, PrintJob $job): JsonResponse
    {
        $job->update([
            'status' => PrintJobStatus::Printed,
            'printed_at' => now(),
            'printed_by' => $request->user('admin')->id,
            'error' => null,
        ]);

        if ($job->document_type === PrintDocument::Kot) {
            $job->reference?->forceFill(['printed_at' => $job->reference->printed_at ?? now()])->save();
        }

        return response()->json(['ok' => true]);
    }

    public function failed(Request $request, PrintJob $job): JsonResponse
    {
        $data = $request->validate(['error' => ['nullable', 'string', 'max:250']]);

        $job->update(['status' => PrintJobStatus::Failed, 'error' => mb_substr($data['error'] ?? 'Print failed.', 0, 250)]);

        return response()->json(['ok' => true]);
    }

    /** Failed / stuck → back in the queue; printed → printed again (a new job). */
    public function retry(PrintJob $job): RedirectResponse
    {
        if ($job->status === PrintJobStatus::Printed) {
            PrintQueue::again($job->load('printer', 'reference'));

            return back()->with('success', "“{$job->title}” sent to print again.");
        }

        if (! $job->printer || $job->printer->isTrashed() || ! $job->printer->is_active) {
            return back()->with('error', "{$job->printer?->name} is not active — pick another printer (kitchen station / cash counter) and print again.");
        }

        $job->update(['status' => PrintJobStatus::Pending, 'error' => null]);
        PrintQueue::announce($job);

        return back()->with('success', "“{$job->title}” is back in the queue.");
    }
}
