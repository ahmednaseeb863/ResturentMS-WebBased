import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, ChevronLeft, Save, Send, XCircle } from 'lucide-react';
import { Button, ConfirmDialog, FilterBar, PageBody, PageStatus, PageToolbar, SearchInput, StatCard, StatGrid, Tag } from '@/components/ui';
import useCan from '@/hooks/useCan';
import { cx, dateTime, money, qty } from '@/lib/format';

/**
 * A count sheet: system quantity (when the count started), what was counted, the
 * difference. Counting → save / submit; submitted → a manager approves or cancels.
 */
export default function StockCountShow({ count }) {
    const can = useCan();
    const editable = count.status.value === 'draft' && can('stock-counts.update');
    const [counted, setCounted] = useState(() => Object.fromEntries(count.items.map((i) => [i.id, i.counted_qty === null ? '' : String(Number(i.counted_qty))])));
    const [search, setSearch] = useState('');
    const [confirm, setConfirm] = useState(null); // 'approve' | 'cancel'
    const [processing, setProcessing] = useState(false);

    const rows = useMemo(
        () =>
            count.items.map((i) => {
                const value = counted[i.id];
                const variance = value === '' || value === undefined ? null : Math.round((Number(value) - Number(i.system_qty)) * 1000) / 1000;
                return { ...i, value, variance };
            }),
        [count.items, counted],
    );
    const shown = rows.filter((r) => !search || r.name.toLowerCase().includes(search.toLowerCase()) || (r.code ?? '').toLowerCase().includes(search.toLowerCase()));
    const done = rows.filter((r) => r.variance !== null);
    const value = done.reduce((n, r) => n + r.variance * Number(r.unit_cost), 0);
    const off = done.filter((r) => r.variance !== 0).length;

    const options = { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false), onSuccess: () => setConfirm(null) };
    const save = (submit = false) =>
        router.put(
            route('stock-counts.update', count.id),
            { counted: Object.fromEntries(Object.entries(counted).map(([k, v]) => [k, v === '' ? null : Number(v)])), submit },
            options,
        );

    return (
        <PageBody>
            <PageToolbar
                title={`${count.code} — ${count.scope}`}
                headTitle={count.code}
                primary={
                    editable ? (
                        <Button variant="primary" icon={Send} disabled={processing || done.length === 0} onClick={() => save(true)}>
                            Submit for Approval
                        </Button>
                    ) : (
                        count.status.value === 'submitted' &&
                        can('stock-counts.approve') && (
                            <Button variant="primary" icon={Check} disabled={processing} onClick={() => setConfirm('approve')}>
                                Approve Corrections
                            </Button>
                        )
                    )
                }
            >
                <Button variant="ghost" icon={ChevronLeft} href={route('stock-counts.index')}>
                    Stock Counts
                </Button>
                {editable && (
                    <Button icon={Save} disabled={processing} onClick={() => save(false)}>
                        Save
                    </Button>
                )}
                {['draft', 'submitted'].includes(count.status.value) && can('stock-counts.cancel') && (
                    <Button variant="ghost" icon={XCircle} className="text-danger" disabled={processing} onClick={() => setConfirm('cancel')}>
                        Cancel Count
                    </Button>
                )}
            </PageToolbar>
            <PageStatus>
                <span>
                    Started {dateTime(count.created_at)} by {count.created_by?.name}
                    {count.submitted_at && ` · submitted by ${count.submitted_by?.name}`}
                    {count.approved_at && ` · approved by ${count.approved_by?.name}`}
                </span>
                <Tag tone={count.status.tone}>{count.status.label}</Tag>
            </PageStatus>

            <StatGrid>
                <StatCard label="Counted" value={`${done.length} / ${rows.length}`} sub="items with a quantity" tone="neutral" />
                <StatCard label="Different" value={off} sub="counted ≠ system" tone={off ? 'danger' : 'neutral'} />
                <StatCard label="Correction value" value={money(count.variance_value ?? value)} sub="at the average cost" tone="neutral" />
            </StatGrid>
            {count.notes && <p className="cell-muted">{count.notes}</p>}

            <FilterBar count={`${shown.length} items`}>
                <SearchInput value={search} onChange={setSearch} placeholder="Find an item…" />
            </FilterBar>

            <div className="rgrid-wrap rgrid-stack">
                <table className="rgrid count-sheet">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th className="text-right">System</th>
                            <th className="text-right">Counted</th>
                            <th className="text-right">Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        {shown.map((r) => (
                            <tr key={r.id} className={cx(r.variance !== null && r.variance !== 0 && 'row-warn')}>
                                <td data-label="Item">
                                    <span className="cell-strong">{r.name}</span>
                                    {r.code && <span className="cell-sub mono">{r.code}</span>}
                                </td>
                                <td data-label="System" className="text-right mono">
                                    {qty(r.system_qty, r.unit)}
                                </td>
                                <td data-label="Counted" className="text-right">
                                    {editable ? (
                                        <span className="count-input">
                                            <input
                                                className="cust-input"
                                                type="number"
                                                min="0"
                                                step="any"
                                                inputMode="decimal"
                                                value={r.value}
                                                aria-label={`Counted ${r.name}`}
                                                onChange={(e) => setCounted((c) => ({ ...c, [r.id]: e.target.value }))}
                                            />
                                            <span className="cell-muted">{r.unit}</span>
                                        </span>
                                    ) : (
                                        <span className="mono">{r.value === '' ? '—' : qty(r.value, r.unit)}</span>
                                    )}
                                </td>
                                <td data-label="Difference" className={cx('text-right mono', r.variance > 0 && 'cash-plus', r.variance < 0 && 'cash-minus')}>
                                    {r.variance === null ? '—' : `${r.variance > 0 ? '+' : ''}${qty(r.variance, r.unit)}`}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <ConfirmDialog
                open={confirm === 'approve'}
                onClose={() => setConfirm(null)}
                title={`Approve ${count.code}?`}
                message={`${off} items are corrected in the stock ledger (${money(value)} at average cost). Items not counted stay as they are.`}
                confirmLabel="Approve"
                processing={processing}
                onConfirm={() => router.put(route('stock-counts.approve', count.id), {}, options)}
            />
            <ConfirmDialog
                open={confirm === 'cancel'}
                onClose={() => setConfirm(null)}
                title={`Cancel ${count.code}?`}
                message="Nothing changes in stock. The sheet is kept as cancelled."
                confirmLabel="Cancel Count"
                danger
                processing={processing}
                onConfirm={() => router.put(route('stock-counts.cancel', count.id), {}, options)}
            />
        </PageBody>
    );
}
