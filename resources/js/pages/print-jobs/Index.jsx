import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Printer, RotateCcw } from 'lucide-react';
import { Button, DataTable, FilterBar, FilterSelect, PageBody, PageStatus, PageToolbar, Tabs, Tag } from '@/components/ui';
import PrintDeviceDialog from '@/components/printing/PrintDeviceDialog';
import { useDevicePrinters } from '@/components/printing/PrintAgent';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import { dateTime } from '@/lib/format';

/**
 * Print queue: kitchen tickets and void slips waiting for / printed by the screens in the
 * branch. Failed jobs go back in the queue; printed ones can be printed again.
 */
export default function PrintJobsIndex({ jobs, filters, counts, statuses, printers }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [device, setDevice] = useState(false);
    const devicePrinters = useDevicePrinters();

    const retry = (job) => router.post(route('print-jobs.retry', job.id), {}, { preserveScroll: true });

    const columns = [
        {
            key: 'title',
            label: 'Document',
            className: 'cell-strong',
            render: (j) => (
                <>
                    {j.title}
                    <span className="cell-sub">
                        {j.document.label}
                        {j.copies > 1 ? ` · ${j.copies} copies` : ''}
                    </span>
                </>
            ),
        },
        { key: 'printer', label: 'Printer', render: (j) => j.printer?.name ?? <span className="cell-muted">—</span> },
        {
            key: 'status',
            label: 'Status',
            render: (j) => (
                <>
                    <Tag tone={j.status.tone}>{j.status.label}</Tag>
                    {j.error && <span className="cell-sub print-error">{j.error}</span>}
                </>
            ),
        },
        { key: 'attempts', label: 'Tries', align: 'right', className: 'mono', render: (j) => j.attempts },
        {
            key: 'created_at',
            label: 'Queued',
            className: 'mono',
            render: (j) => (
                <>
                    {dateTime(j.created_at)}
                    {j.created_by && <span className="cell-sub">{j.created_by}</span>}
                </>
            ),
        },
        {
            key: 'printed_at',
            label: 'Printed',
            className: 'mono',
            render: (j) =>
                j.printed_at ? (
                    <>
                        {dateTime(j.printed_at)}
                        {j.printed_by && <span className="cell-sub">{j.printed_by}</span>}
                    </>
                ) : (
                    <span className="cell-muted">—</span>
                ),
        },
        ...(can('print-jobs.retry')
            ? [
                  {
                      key: 'actions',
                      label: '',
                      align: 'right',
                      render: (j) =>
                          j.status.value === 'pending' ? null : (
                              <Button variant="ghost" icon={RotateCcw} onClick={() => retry(j)}>
                                  {j.status.value === 'printed' ? 'Print again' : 'Retry'}
                              </Button>
                          ),
                  },
              ]
            : []),
    ];

    const total = Object.values(counts).reduce((a, b) => a + b, 0);

    return (
        <PageBody>
            <PageToolbar title="Print Queue">
                {can('print-jobs.pending') && (
                    <Button icon={Printer} onClick={() => setDevice(true)}>
                        This Screen Prints For ({devicePrinters.filter((id) => printers.some((p) => p.value === id)).length})
                    </Button>
                )}
                {can('printers.index') && (
                    <Button variant="ghost" href={route('printers.index')}>
                        Printers
                    </Button>
                )}
            </PageToolbar>
            <PageStatus>
                <span>
                    {counts.pending} waiting · {counts.failed} failed
                </span>
            </PageStatus>

            <Tabs
                tabs={[
                    { key: '', label: 'All', count: total },
                    ...statuses.map((s) => ({ key: s.value, label: s.label, count: counts[s.value] })),
                ]}
                value={query.status}
                onChange={(v) => setQuery('status', v)}
            />
            <FilterBar count={`${jobs.meta.total} jobs`}>
                <FilterSelect
                    label="Printer"
                    value={query.printer}
                    onChange={(v) => setQuery('printer', v)}
                    options={[{ value: '', label: 'All' }, ...printers]}
                />
            </FilterBar>

            <DataTable columns={columns} rows={jobs.data} meta={jobs.meta} noun="jobs" empty="Nothing printed yet" stack />

            {device && (
                <PrintDeviceDialog
                    printers={printers.map((p) => ({ id: p.value, name: p.label, type: p.type }))}
                    onClose={() => setDevice(false)}
                />
            )}
        </PageBody>
    );
}
