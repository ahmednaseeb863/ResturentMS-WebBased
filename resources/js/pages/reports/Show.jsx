import { router } from '@inertiajs/react';
import { ArrowLeft, FileSpreadsheet, FileText } from 'lucide-react';
import { Button, ChartBox, FilterBar, FilterSelect, PageBody, PageStatus, PageToolbar } from '@/components/ui';
import useCan from '@/hooks/useCan';
import { cx, date, money, number } from '@/lib/format';

const NUMERIC = ['money', 'qty', 'int', 'percent'];

/** yyyy-mm-dd arithmetic in local time (business dates have no clock). */
function shift(iso, { days = 0, months = 0 } = {}) {
    const [y, m, d] = iso.split('-').map(Number);
    const dt = new Date(y, m - 1 + months, d + days);
    return `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;
}

function presets(today) {
    const monthStart = `${today.slice(0, 8)}01`;
    const [y, m, d] = today.split('-').map(Number);
    const weekday = (new Date(y, m - 1, d).getDay() + 6) % 7; // Monday = 0
    const lastMonthStart = shift(monthStart, { months: -1 });

    return [
        { value: 'today', label: 'Today', from: today, to: today },
        { value: 'yesterday', label: 'Yesterday', from: shift(today, { days: -1 }), to: shift(today, { days: -1 }) },
        { value: 'week', label: 'This week', from: shift(today, { days: -weekday }), to: today },
        { value: '7', label: 'Last 7 days', from: shift(today, { days: -6 }), to: today },
        { value: 'month', label: 'This month', from: monthStart, to: today },
        { value: 'last_month', label: 'Last month', from: lastMonthStart, to: shift(monthStart, { days: -1 }) },
        { value: '30', label: 'Last 30 days', from: shift(today, { days: -29 }), to: today },
        { value: 'year', label: 'This year', from: `${today.slice(0, 4)}-01-01`, to: today },
    ];
}

function cell(value, type) {
    if (value === null || value === undefined || value === '') return type === 'text' ? '—' : '';
    switch (type) {
        case 'money':
            return money(value);
        case 'qty':
            return Number(value).toLocaleString(undefined, { maximumFractionDigits: 3 });
        case 'int':
            return number(value);
        case 'percent':
            return `${Number(value).toFixed(1)}%`;
        case 'date':
            return date(value);
        default:
            return value;
    }
}

/** One report on screen: date range + branch filters, bar chart, table with totals, Excel / PDF. */
export default function ReportShow({ report, rows, totals, filters, meta, branchOptions, groupTitle }) {
    const can = useCan();
    const ranges = presets(meta.today);
    const preset = ranges.find((p) => p.from === filters.from && p.to === filters.to)?.value ?? 'custom';

    function go(changes) {
        router.get(route(`reports.${report.group}`, report.key), { ...filters, ...changes }, { preserveScroll: true, preserveState: true, replace: true });
    }

    const exportUrl = (format) =>
        route('reports.export', { group: report.group, report: report.key, format, from: filters.from, to: filters.to, branch: filters.branch });

    const chart = report.chart && rows.length > 1 ? report.chart : null;
    const labelType = chart ? report.columns.find((c) => c.key === chart.label)?.type : null;
    const bars = chart
        ? rows.slice(0, 40).map((r) => ({
              label: labelType === 'date' ? r[chart.label].slice(5).replace('-', '/') : String(r[chart.label]),
              title: `${labelType === 'date' ? date(r[chart.label]) : r[chart.label]} — ${cell(r[chart.value], report.columns.find((c) => c.key === chart.value)?.type)}`,
              value: Math.max(0, Number(r[chart.value]) || 0),
          }))
        : [];
    const max = Math.max(0, ...bars.map((b) => b.value));

    return (
        <PageBody>
            <PageToolbar
                title={report.title}
                primary={
                    can('reports.export') && (
                        <a className="btn btn-primary" href={exportUrl('xlsx')}>
                            <FileSpreadsheet strokeWidth={1.5} />
                            Excel
                        </a>
                    )
                }
            >
                <Button icon={ArrowLeft} href={route('reports.index')}>
                    Reports
                </Button>
                {can('reports.export') && (
                    <a className="btn btn-secondary" href={exportUrl('pdf')}>
                        <FileText strokeWidth={1.5} />
                        PDF
                    </a>
                )}
            </PageToolbar>
            <PageStatus>
                <span>
                    {groupTitle} · {meta.branch_label} · {report.uses_dates ? meta.period_label : 'as of now'}
                </span>
            </PageStatus>

            <div className="rpt-desc-line">{report.description}</div>

            <FilterBar count={`${rows.length} ${rows.length === 1 ? 'row' : 'rows'}`}>
                {report.uses_dates && (
                    <>
                        <FilterSelect
                            label="Period"
                            value={preset}
                            onChange={(v) => {
                                const p = ranges.find((r) => r.value === v);
                                if (p) go({ from: p.from, to: p.to });
                            }}
                            options={[...ranges.map(({ value, label }) => ({ value, label })), ...(preset === 'custom' ? [{ value: 'custom', label: 'Custom' }] : [])]}
                        />
                        <span className="flabel">From:</span>
                        <input type="date" className="fselect ledger-date" value={filters.from} max={meta.today} onChange={(e) => e.target.value && go({ from: e.target.value })} />
                        <span className="flabel">To:</span>
                        <input type="date" className="fselect ledger-date" value={filters.to} max={meta.today} onChange={(e) => e.target.value && go({ to: e.target.value })} />
                    </>
                )}
                {branchOptions.length > 0 && <FilterSelect label="Branch" value={filters.branch} onChange={(v) => go({ branch: v })} options={branchOptions} />}
            </FilterBar>

            {chart && max > 0 && (
                <ChartBox title={report.columns.find((c) => c.key === chart.value)?.label} className="rpt-chart">
                    <div className="bars">
                        {bars.map((b, i) => (
                            // data-driven height: the one allowed inline style (a CSS variable)
                            // eslint-disable-next-line react/forbid-dom-props
                            <div key={i} className="bar" title={b.title} style={{ '--bar-h': `${Math.max(2, Math.round((b.value / max) * 100))}%` }} />
                        ))}
                    </div>
                    <div className="bar-labels">
                        {bars.map((b, i) => (
                            <span key={i}>{b.label}</span>
                        ))}
                    </div>
                </ChartBox>
            )}

            <div className="rgrid-wrap rgrid-stack">
                <table className="rgrid rpt-table">
                    <thead>
                        <tr>
                            {report.columns.map((c) => (
                                <th key={c.key} className={cx(NUMERIC.includes(c.type) && 'text-right')}>
                                    {c.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td colSpan={report.columns.length} className="products-empty">
                                    {report.uses_dates ? 'Nothing in this period' : 'Nothing to show'}
                                </td>
                            </tr>
                        ) : (
                            rows.map((row, i) => (
                                <tr key={i} className={cx(row.strong && 'rpt-strong')}>
                                    {report.columns.map((c, ci) => (
                                        <td
                                            key={c.key}
                                            data-label={c.label}
                                            className={cx(NUMERIC.includes(c.type) && 'text-right mono', ci === 0 && 'cell-strong', Number(row[c.key]) < 0 && c.type === 'money' && 'rpt-negative')}
                                        >
                                            {cell(row[c.key], c.type)}
                                        </td>
                                    ))}
                                </tr>
                            ))
                        )}
                    </tbody>
                    {totals && rows.length > 0 && (
                        <tfoot>
                            <tr className="rpt-total">
                                {report.columns.map((c, ci) => (
                                    <td key={c.key} data-label={c.label} className={cx(NUMERIC.includes(c.type) && 'text-right mono')}>
                                        {ci === 0 ? 'Total' : totals[c.key] === undefined ? '' : cell(totals[c.key], c.type)}
                                    </td>
                                ))}
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </PageBody>
    );
}
