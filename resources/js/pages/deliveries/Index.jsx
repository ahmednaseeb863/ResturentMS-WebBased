import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Bike, CheckCircle2, MapPin, PackageCheck, Undo2, Wallet, XCircle } from 'lucide-react';
import { Button, ConfirmDialog, DataTable, FilterBar, Input, PageBody, PageStatus, PageToolbar, SearchInput, Tabs, Tag } from '@/components/ui';
import FailDialog from '@/components/deliveries/FailDialog';
import { mapsUrl } from '@/components/deliveries/links';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useLive from '@/hooks/useLive';
import useNow from '@/hooks/useNow';
import { money, since, time } from '@/lib/format';

/**
 * Deliveries board (PLAN §4.14): deliveries to send out with their kitchen status, give
 * them to riders, and mark them out / delivered / failed / returned for a rider without
 * the rider panel. The Done tab lists a business day's finished deliveries. Refreshes
 * itself (polled).
 */
export default function DeliveriesIndex({ deliveries, riders, filters, counts, cashHeld, businessDate }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [dialog, setDialog] = useState(null); // { kind, delivery }
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});
    const now = useNow(30000);
    const done = filters.view === 'done';

    useLive('deliveries', () => router.reload({ only: ['deliveries', 'riders', 'counts', 'cashHeld'] }));
    useLive('kitchen', () => router.reload({ only: ['deliveries'] }));

    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onSuccess: () => {
            setDialog(null);
            setErrors({});
        },
        onError: setErrors,
    };
    const assign = (d, rider) => router.put(route('deliveries.assign', d.id), { rider: rider || null }, options);
    const move = (d, action, reason = null) => router.put(route('deliveries.status', d.id), { action, reason }, options);

    const riderOptions = riders.map((r) => ({ value: r.id, label: r.active ? `${r.name} · ${r.active} out` : r.name }));
    const active = counts.pending + counts.assigned + counts.out_for_delivery + counts.failed;

    const columns = [
        {
            key: 'order',
            label: 'Order',
            render: (d) => (
                <>
                    <Link href={route('orders.show', d.order.id)} className="cell-strong mono" onClick={(e) => e.stopPropagation()}>
                        {d.order.code}
                    </Link>
                    <span className="cell-sub">{done ? time(d.delivered_at ?? d.returned_at) : since(d.order.placed_at, now)}</span>
                </>
            ),
        },
        {
            key: 'customer',
            label: 'Deliver To',
            render: (d) => (
                <>
                    <span className="cell-strong">{d.order.customer ?? '—'}</span>
                    <a className="cell-sub delivery-address" href={mapsUrl(d.address)} target="_blank" rel="noreferrer">
                        <MapPin size={11} strokeWidth={1.5} /> {d.address}
                    </a>
                    {d.phone && <span className="cell-sub mono">{d.phone}</span>}
                </>
            ),
        },
        { key: 'zone', label: 'Zone', render: (d) => d.zone?.name ?? <span className="cell-muted">—</span> },
        {
            key: 'amount',
            label: 'Bill',
            align: 'right',
            render: (d) => (
                <>
                    <span className="mono">{money(d.order.total)}</span>
                    <span className="cell-sub">
                        {Number(d.cash_collected) > 0
                            ? `${money(d.cash_collected)} cash${d.settled_at ? ' · settled' : ' · with rider'}`
                            : Number(d.order.due) > 0
                              ? `Collect ${money(d.order.due)}`
                              : 'Paid'}
                    </span>
                </>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            render: (d) => (
                <span className="delivery-tags">
                    <Tag tone={d.status.tone}>{d.status.label}</Tag>
                    {d.status.value !== 'out_for_delivery' && d.status.value !== 'delivered' && (
                        <Tag tone={d.order.cooking ? 'warn' : 'accent'}>{d.order.cooking ? 'Cooking' : 'Food ready'}</Tag>
                    )}
                    {d.failed_reason && <span className="cell-sub">{d.failed_reason}</span>}
                </span>
            ),
        },
        {
            key: 'rider',
            label: 'Rider',
            render: (d) =>
                !done && can('deliveries.assign') && ['pending', 'assigned', 'returned'].includes(d.status.value) ? (
                    <select
                        className="fselect delivery-rider"
                        value={d.rider?.id ?? ''}
                        disabled={processing}
                        aria-label={`Rider for ${d.order.code}`}
                        onChange={(e) => assign(d, e.target.value)}
                    >
                        <option value="">No rider</option>
                        {riderOptions.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                ) : (
                    <>
                        {d.rider?.name ?? <span className="cell-muted">—</span>}
                        {d.picked_up_at && <span className="cell-sub">Left {time(d.picked_up_at)}</span>}
                    </>
                ),
        },
    ];

    if (!done && can('deliveries.status')) {
        columns.push({
            key: 'actions',
            label: '',
            align: 'right',
            render: (d) => {
                const s = d.status.value;
                return (
                    <span className="delivery-actions">
                        {s === 'assigned' && (
                            <Button icon={PackageCheck} disabled={processing || d.order.cooking} onClick={() => move(d, 'out')}>
                                Out
                            </Button>
                        )}
                        {(s === 'out_for_delivery' || s === 'failed') && (
                            <>
                                <Button variant="primary" icon={CheckCircle2} disabled={processing} onClick={() => setDialog({ kind: 'deliver', delivery: d })}>
                                    Delivered
                                </Button>
                                {s === 'out_for_delivery' && (
                                    <Button variant="ghost" icon={XCircle} disabled={processing} onClick={() => setDialog({ kind: 'fail', delivery: d })}>
                                        Failed
                                    </Button>
                                )}
                                <Button variant="ghost" icon={Undo2} disabled={processing} onClick={() => setDialog({ kind: 'return', delivery: d })}>
                                    Returned
                                </Button>
                            </>
                        )}
                    </span>
                );
            },
        });
    }

    return (
        <PageBody>
            <PageToolbar
                title="Deliveries"
                primary={
                    can('riders.index') && (
                        <Button variant="primary" icon={Wallet} href={route('riders.index')}>
                            Rider Cash · {money(cashHeld)}
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {counts.pending} unassigned · {counts.assigned} waiting for pickup · {counts.out_for_delivery} on the road
                    {counts.failed > 0 && ` · ${counts.failed} failed`}
                </span>
            </PageStatus>

            {riders.length > 0 && !done && (
                <div className="delivery-riders">
                    {riders.map((r) => (
                        <span key={r.id} className={r.active ? 'delivery-rider-chip is-busy' : 'delivery-rider-chip'}>
                            <Bike size={13} strokeWidth={1.5} />
                            <strong>{r.name}</strong>
                            <span>{r.active ? `${r.active} out` : 'free'}</span>
                            {r.cash_held > 0 && <span className="mono">{money(r.cash_held)}</span>}
                        </span>
                    ))}
                </div>
            )}

            <Tabs
                value={filters.view}
                onChange={(view) => setQuery('view', view)}
                tabs={[
                    { key: 'active', label: 'To Deliver', count: active },
                    { key: 'done', label: 'Done', count: counts.done },
                ]}
            />

            <FilterBar count={`${deliveries.length} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Order, customer, phone, address…" />
                {done && (
                    <Input mono type="date" value={query.date ?? businessDate} max={businessDate} onChange={(e) => setQuery('date', e.target.value)} aria-label="Business date" />
                )}
            </FilterBar>

            {errors.delivery || errors.rider ? <div className="pos-error">{errors.delivery ?? errors.rider}</div> : null}

            <DataTable
                columns={columns}
                rows={deliveries}
                noun="deliveries"
                empty={done ? 'No deliveries finished that day' : 'No deliveries to send out'}
                rowClassName={(d) => (d.status.value === 'failed' ? 'row-warn' : undefined)}
                stack
            />

            {dialog?.kind === 'fail' && (
                <FailDialog
                    delivery={dialog.delivery}
                    processing={processing}
                    error={errors.reason ?? errors.delivery}
                    onSubmit={(reason) => move(dialog.delivery, 'fail', reason)}
                    onClose={() => setDialog(null)}
                />
            )}
            <ConfirmDialog
                open={dialog?.kind === 'deliver'}
                onClose={() => setDialog(null)}
                title={dialog ? `Delivered ${dialog.delivery.order.code}?` : ''}
                message={
                    dialog && Number(dialog.delivery.order.due) > 0
                        ? `${dialog.delivery.rider?.name ?? 'The rider'} collected ${money(dialog.delivery.order.due)} cash — it stays with the rider until it is settled.`
                        : 'The order is already paid.'
                }
                confirmLabel="Delivered"
                processing={processing}
                onConfirm={() => move(dialog.delivery, 'deliver')}
            />
            <ConfirmDialog
                open={dialog?.kind === 'return'}
                onClose={() => setDialog(null)}
                title={dialog ? `${dialog.delivery.order.code} back at the shop?` : ''}
                message="The order is ready again — give it to a rider or cancel it."
                confirmLabel="Returned"
                processing={processing}
                onConfirm={() => move(dialog.delivery, 'return')}
            />
        </PageBody>
    );
}
