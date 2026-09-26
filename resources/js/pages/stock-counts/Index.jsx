import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Button, DataTable, Dialog, Field, FilterBar, FilterSelect, FormGrid, PageBody, PageStatus, PageToolbar, Select, Tag, Textarea } from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import { dateTime, money } from '@/lib/format';

/** Stock counts (PLAN §4.16): count sheets and their status. */
export default function StockCountsIndex({ counts, filters, statuses, categories }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [starting, setStarting] = useState(false);

    const columns = [
        {
            key: 'code',
            label: 'Count',
            render: (c) => (
                <>
                    <span className="cell-strong mono">{c.code}</span>
                    <span className="cell-sub">{c.scope}</span>
                </>
            ),
        },
        { key: 'status', label: 'Status', render: (c) => <Tag tone={c.status.tone}>{c.status.label}</Tag> },
        { key: 'progress', label: 'Counted', align: 'right', className: 'mono', render: (c) => `${c.counted_count ?? 0} / ${c.items_count}` },
        {
            key: 'started',
            label: 'Started',
            render: (c) => (
                <>
                    {dateTime(c.created_at)}
                    <span className="cell-sub">{c.created_by?.name}</span>
                </>
            ),
        },
        {
            key: 'result',
            label: 'Correction',
            align: 'right',
            render: (c) =>
                c.variance_value !== null ? (
                    <>
                        <span className="mono">{money(c.variance_value)}</span>
                        <span className="cell-sub">by {c.approved_by?.name}</span>
                    </>
                ) : (
                    <span className="cell-muted">—</span>
                ),
        },
    ];

    return (
        <PageBody>
            <PageToolbar
                title="Stock Counts"
                primary={
                    can('stock-counts.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setStarting(true)}>
                            Start Count
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>Count what is on the shelf — a manager approves the differences into the stock ledger</span>
            </PageStatus>

            <FilterBar count={`${counts.meta.total} counts`}>
                <FilterSelect label="Status" value={query.status} onChange={(v) => setQuery('status', v)} options={[{ value: '', label: 'All' }, ...statuses]} />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={counts.data}
                meta={counts.meta}
                noun="counts"
                empty="No stock counts yet"
                onRowClick={can('stock-counts.show') ? (c) => router.visit(route('stock-counts.show', c.id)) : undefined}
                rowClassName={(c) => (c.status.value === 'cancelled' ? 'row-dim' : undefined)}
                stack
            />

            {starting && <StartDialog categories={categories} onClose={() => setStarting(false)} />}
        </PageBody>
    );
}

function StartDialog({ categories, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ kind: 'all', category: '', notes: '' });

    return (
        <Dialog
            open
            onClose={onClose}
            title="Start a Stock Count"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" disabled={processing} onClick={() => post(route('stock-counts.store'))}>
                        {processing ? 'Starting…' : 'Start Count'}
                    </Button>
                </>
            }
        >
            <p className="ui-dialog-text">Today’s stock of each item is noted now; sales during the count don’t change the result.</p>
            <FormGrid>
                <Field label="Count" required error={errors.kind}>
                    <Select
                        value={data.kind}
                        options={[
                            { value: 'all', label: 'All stock' },
                            { value: 'raw_material', label: 'Raw materials' },
                            { value: 'ready_item', label: 'Ready items' },
                        ]}
                        onChange={(e) => setData('kind', e.target.value)}
                    />
                </Field>
                {data.kind === 'raw_material' && (
                    <Field label="Category" error={errors.category}>
                        <Select value={data.category} placeholder="All categories" options={categories} onChange={(e) => setData('category', e.target.value)} />
                    </Field>
                )}
                <Field label="Notes" full error={errors.notes}>
                    <Textarea value={data.notes} placeholder="Who counts, which store room…" onChange={(e) => setData('notes', e.target.value)} />
                </Field>
            </FormGrid>
        </Dialog>
    );
}
