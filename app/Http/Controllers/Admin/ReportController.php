<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\Report;
use App\Reports\ReportExport;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Support\BranchPicker;
use App\Support\BusinessDate;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Reports (PLAN §4.19): the Reports screen (cards per group), one report on screen with its
 * filters (business-date range, branch or all branches), and the same report as Excel / PDF.
 * Each group is one permission: reports.sales / reports.cash / reports.inventory / reports.finance.
 */
class ReportController extends Controller
{
    private const MAX_DAYS = 366;

    public function index(Request $request): Response
    {
        return Inertia::render('reports/Index', [
            'groups' => ReportRegistry::menu($request->user('admin')),
        ]);
    }

    public function show(Request $request, string $report): Response
    {
        $report = $this->report($request, $request->route('group'), $report);
        $filters = $this->filters($request);

        return Inertia::render('reports/Show', [
            'report' => [
                'key' => $report->key(),
                'group' => $report->group(),
                'title' => $report->title(),
                'description' => $report->description(),
                'uses_dates' => $report->usesDates(),
                'columns' => $report->columns(),
                'chart' => $report->chart(),
            ],
            ...$this->run($report, $filters),
            'filters' => [
                'from' => $filters->from,
                'to' => $filters->to,
                'branch' => BranchPicker::selected($filters->branches),
            ],
            'meta' => [
                'branch_label' => $filters->branchLabel(),
                'period_label' => $filters->periodLabel(),
                'today' => BusinessDate::for($filters->mainBranchId()),
                'currency' => setting('general.currency_symbol', $filters->mainBranchId()),
            ],
            'branchOptions' => BranchPicker::options($request->user('admin')),
            'groupTitle' => Report::GROUPS[$report->group()]['title'],
        ]);
    }

    public function export(Request $request, string $group, string $report): HttpResponse
    {
        $report = $this->report($request, $group, $report);
        $filters = $this->filters($request);
        $result = $this->run($report, $filters);
        $name = Str::slug($report->title().' '.$filters->branchLabel().' '.($report->usesDates() ? $filters->from.' '.$filters->to : now()->toDateString()));

        if ($request->query('format') === 'pdf') {
            return Pdf::loadView('reports.pdf', [
                'report' => $report,
                'filters' => $filters,
                'rows' => $result['rows'],
                'totals' => $result['totals'],
                'branchId' => $filters->mainBranchId(),
            ])->setPaper('a4', $report->landscape() ? 'landscape' : 'portrait')->download("{$name}.pdf");
        }

        return Excel::download(new ReportExport($report, $filters, $result), "{$name}.xlsx");
    }

    private function report(Request $request, ?string $group, string $key): Report
    {
        $report = $group ? ReportRegistry::find($group, $key) : null;
        abort_unless($report, 404);
        abort_unless($request->user('admin')->canRoute(Report::GROUPS[$report->group()]['route']), 403);

        return $report;
    }

    private function filters(Request $request): ReportFilters
    {
        $branches = BranchPicker::branches($request);
        abort_if($branches->isEmpty(), 403);

        $today = CarbonImmutable::parse(BusinessDate::for($branches->first()->id));
        $date = fn (string $key, CarbonImmutable $default) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) && strtotime($request->query($key))
            ? CarbonImmutable::parse($request->query($key))
            : $default;

        $from = $date('from', $today->startOfMonth());
        $to = $date('to', $today);
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS);
        }

        return new ReportFilters($from->toDateString(), $to->toDateString(), $branches);
    }

    /** @return array{rows: list<array>, totals: ?array} */
    private function run(Report $report, ReportFilters $filters): array
    {
        $rows = $report->rows($filters);

        return ['rows' => $rows, 'totals' => $report->totals($rows)];
    }
}
