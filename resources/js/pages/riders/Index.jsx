import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Clock, Truck, Wallet } from 'lucide-react';
import { Button, ConfirmDialog, DataTable, PageBody, PageStatus, PageToolbar, StatCard, StatGrid, Tag } from '@/components/ui';
import useCan from '@/hooks/useCan';
import useLive from '@/hooks/useLive';
import { money, time } from '@/lib/format';

/**
 * Riders (PLAN §4.14): who is out, deliveries today, and the COD cash each rider holds —
 * settled into the cashier's open shift as a rider settlement. Riders who left still owe
 * what they hold, so the cash list comes from the deliveries, not the rider list.
 */
export default function RidersIndex({ riders, unsettled, cashHeld }) {
    const { context } = usePage().props;
    const can = useCan();
    const [settling, setSettling] = useState(null); // { rider, amount, count }
    const [processing, setProcessing] = useState(false);

    useLive('deliveries', () => router.reload({ only: ['riders', 'unsettled', 'cashHeld'] }));

    // cash per rider from the unsettled deliveries (includes riders no longer active)
    const byRider = unsettled.reduce((map, d) => {
        const key = d.rider?.id ?? 'none';
        const row = map.get(key) ?? { rider: d.rider, amount: 0, deliveries: [] };
        row.amount += Number(d.cash_collected);
        row.deliveries.push(d);
        return map.set(key, row);
    }, new Map());

    const canSettle = can('riders.settle');
    const settle = () =>
        router.post(route('riders.settle', settling.rider.id), {}, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => setSettling(null),
        });

    const columns = [
        {
            key: 'name',
            label: 'Rider',
            render: (r) => (
                <>
                    <span className="cell-strong">{r.name}</span>
                    {!r.has_login && <span className="cell-sub">No login — the counter marks their deliveries</span>}
                </>
            ),
        },
        { key: 'phone', label: 'Phone', className: 'mono', render: (r) => r.phone ?? '—' },
        { key: 'active', label: 'Out Now', align: 'center', render: (r) => (r.active ? <Tag tone="info">{r.active}</Tag> : <span className="cell-muted">Free</span>) },
        { key: 'today', label: 'Delivered Today', align: 'center', className: 'mono', render: (r) => r.delivered_today },
        {
            key: 'cash',
            label: 'Cash Held',
            align: 'right',
            render: (r) => (r.cash_held > 0 ? <strong className="mono">{money(r.cash_held)}</strong> : <span className="cell-muted">—</span>),
        },
    ];

    return (
        <PageBody>
            <PageToolbar
                title="Riders"
                primary={
                    can('deliveries.index') && (
                        <Button variant="primary" icon={Truck} href={route('deliveries.index')}>
                            Deliveries
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {riders.length} active riders · {money(cashHeld)} cash with riders
                </span>
            </PageStatus>

            <StatGrid>
                <StatCard label="Cash with riders" value={money(cashHeld)} sub={`${unsettled.length} deliveries not settled`} tone={cashHeld > 0 ? 'danger' : 'neutral'} />
                <StatCard label="Out now" value={riders.reduce((n, r) => n + r.active, 0)} sub="deliveries with riders" />
                <StatCard label="Delivered today" value={riders.reduce((n, r) => n + r.delivered_today, 0)} sub="this business day" />
            </StatGrid>

            <DataTable columns={columns} rows={riders} noun="riders" empty="No active riders — add employees with the Rider designation" stack />

            <div className="rider-settle-title">Cash to Settle</div>
            {canSettle && !context.shift && byRider.size > 0 && (
                <div className="pos-no-shift">
                    <Clock size={14} strokeWidth={1.5} />
                    <span>Open your shift to take riders’ cash into your drawer.</span>
                </div>
            )}
            {byRider.size === 0 ? (
                <p className="cell-muted">Riders hold no cash.</p>
            ) : (
                <div className="rider-settle-list">
                    {[...byRider.values()].map(({ rider, amount, deliveries }) => (
                        <div key={rider?.id ?? 'none'} className="scard rider-settle">
                            <div className="rider-settle-head">
                                <strong>{rider?.name ?? 'Unknown rider'}</strong>
                                <span className="mono rider-settle-amount">{money(amount)}</span>
                                {canSettle && rider && (
                                    <Button
                                        variant="primary"
                                        icon={Wallet}
                                        disabled={!context.shift || processing}
                                        onClick={() => setSettling({ rider, amount, count: deliveries.length })}
                                    >
                                        Settle
                                    </Button>
                                )}
                            </div>
                            <div className="rider-settle-rows">
                                {deliveries.map((d) => (
                                    <div key={d.id} className="rider-row">
                                        <Link href={route('orders.show', d.order.id)} className="mono">
                                            {d.order.code}
                                        </Link>
                                        <span>{d.order.customer ?? d.address}</span>
                                        <span className="cell-muted">{time(d.delivered_at)}</span>
                                        <span className="mono">{money(d.cash_collected)}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <ConfirmDialog
                open={Boolean(settling)}
                onClose={() => setSettling(null)}
                title={settling ? `Settle ${settling.rider.name}’s cash?` : ''}
                message={
                    settling && context.shift
                        ? `Count ${money(settling.amount)} from ${settling.count} ${settling.count === 1 ? 'delivery' : 'deliveries'} — it goes into shift ${context.shift.code} as a rider settlement.`
                        : ''
                }
                confirmLabel={settling ? `Take ${money(settling.amount)}` : 'Settle'}
                processing={processing}
                onConfirm={settle}
            />
        </PageBody>
    );
}
