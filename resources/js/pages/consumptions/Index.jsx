import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { CheckCheck, Pencil, Scale } from 'lucide-react';
import { Button, DataTable, PageBody, PageStatus, PageToolbar, Tabs, Tag } from '@/components/ui';
import ConsumptionDialog from '@/components/kitchen/ConsumptionDialog';
import useCan from '@/hooks/useCan';
import { cx, dateTime, qty } from '@/lib/format';

const RULES = {
    order_completion: 'when the order completes',
    shift_close: 'when a shift closes',
    never: 'never — confirm them here',
};

/**
 * Pending consumption (PLAN §4.16): order lines whose raw materials nobody confirmed
 * (kitchens working from printed tickets) — confirm at the recipe or adjust — and the lines
 * auto-confirmed in the last 7 days, which a manager can correct (a correction entry).
 */
export default function ConsumptionsIndex({ view, pending, auto, pendingCount, rule }) {
    const { materials } = usePage().props;
    const can = useCan();
    const [dialog, setDialog] = useState(null); // { kind: 'confirm' | 'adjust', row }
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState(null);

    const options = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onSuccess: () => {
            setDialog(null);
            setError(null);
        },
        onError: (errs) => setError(errs.consumption ?? Object.values(errs)[0]),
    };
    const confirmAtRecipe = (ids) => router.post(route('consumptions.confirm'), { items: ids }, options);

    function open(kind, row) {
        const go = () => setDialog({ kind, row });
        if (materials) go();
        else router.reload({ only: ['materials'], onSuccess: go });
    }

    const pendingColumns = [
        {
            key: 'line',
            label: 'Order Line',
            render: (r) => (
                <>
                    <span className="cell-strong">
                        {r.quantity} × {r.name}
                    </span>
                    {r.extras.length > 0 && <span className="cell-sub">{r.extras.join(', ')}</span>}
                </>
            ),
        },
        {
            key: 'order',
            label: 'Order',
            render: (r) => (
                <>
                    <Link href={route('orders.show', r.order.id)} className="mono">
                        {r.order.code}
                    </Link>
                    <span className="cell-sub">
                        {r.order.status}
                        {r.kitchen && ` · kitchen ${r.kitchen.toLowerCase()}`}
                    </span>
                </>
            ),
        },
        { key: 'sent', label: 'Sent', className: 'mono cell-muted', render: (r) => dateTime(r.sent_at) },
        { key: 'recipe', label: 'Recipe', render: (r) => r.materials.map((m) => `${m.name} ${m.expected_text}`).join(', ') },
        {
            key: 'actions',
            label: '',
            align: 'right',
            render: (r) =>
                can('consumptions.confirm') && (
                    <span className="cell-actions">
                        <Button variant="ghost" className="btn-xs" icon={Pencil} disabled={processing} onClick={() => open('confirm', r)}>
                            Adjust
                        </Button>
                        <Button className="btn-xs" disabled={processing} onClick={() => confirmAtRecipe([r.id])}>
                            Confirm
                        </Button>
                    </span>
                ),
        },
    ];

    const autoColumns = [
        pendingColumns[0],
        pendingColumns[1],
        {
            key: 'used',
            label: 'Used (recipe)',
            render: (r) =>
                r.materials.map((m) => (
                    <span key={m.id} className={cx('cell-sub', m.used !== m.recipe && 'consumption-off')}>
                        {m.name} {qty(m.used, m.unit)}
                        {m.used !== m.recipe && ` (recipe ${qty(m.recipe, m.unit)})`}
                        {m.corrected && ' · corrected'}
                    </span>
                )),
        },
        {
            key: 'actions',
            label: '',
            align: 'right',
            render: (r) =>
                can('consumptions.adjust') && (
                    <Button variant="ghost" className="btn-xs" icon={Pencil} disabled={processing} onClick={() => open('adjust', r)}>
                        Correct
                    </Button>
                ),
        },
    ];

    return (
        <PageBody>
            <PageToolbar
                title="Pending Consumption"
                primary={
                    view === 'pending' &&
                    can('consumptions.confirm') &&
                    pending.length > 0 && (
                        <Button variant="primary" icon={CheckCheck} disabled={processing} onClick={() => confirmAtRecipe(pending.map((r) => r.id))}>
                            Confirm All at Recipe ({pending.length})
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    <Scale size={12} strokeWidth={1.5} /> Unconfirmed raw materials are deducted automatically {RULES[rule] ?? rule} (Settings → Inventory)
                </span>
            </PageStatus>

            <Tabs
                value={view}
                onChange={(v) => router.get(route('consumptions.pending'), v === 'auto' ? { view: 'auto' } : {}, { preserveState: false })}
                tabs={[
                    { key: 'pending', label: 'Not Confirmed', count: pendingCount },
                    { key: 'auto', label: 'Auto-confirmed (7 days)' },
                ]}
            />
            {error && !dialog && <div className="pos-error">{error}</div>}

            {view === 'pending' ? (
                <DataTable columns={pendingColumns} rows={pending} noun="lines" empty="Nothing waiting — every prepared line is confirmed" stack />
            ) : (
                <DataTable columns={autoColumns} rows={auto} noun="lines" empty="Nothing was auto-confirmed lately" stack />
            )}

            {dialog && (
                <ConsumptionDialog
                    title={dialog.kind === 'confirm' ? `Raw materials — ${dialog.row.order.code}` : `Correct — ${dialog.row.order.code}`}
                    rows={[dialog.row]}
                    materials={materials ?? []}
                    processing={processing}
                    error={error}
                    onConfirm={(consumption) =>
                        dialog.kind === 'confirm'
                            ? router.post(route('consumptions.confirm'), { consumption }, options)
                            : router.put(route('consumptions.adjust', dialog.row.id), { consumption }, options)
                    }
                    onClose={() => {
                        setDialog(null);
                        setError(null);
                    }}
                />
            )}
            {view === 'auto' && <Tag tone="neutral">Corrections are added as new entries — the original stays in the ledger</Tag>}
        </PageBody>
    );
}
