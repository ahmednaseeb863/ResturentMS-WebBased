import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Bike, CheckCircle2, MapPin, Navigation, PackageCheck, Phone, Wallet, XCircle } from 'lucide-react';
import { ConfirmDialog, EmptyState, PageToolbar, Tag } from '@/components/ui';
import FailDialog from '@/components/deliveries/FailDialog';
import { mapsUrl, telUrl } from '@/components/deliveries/links';
import useLive from '@/hooks/useLive';
import useNow from '@/hooks/useNow';
import { cx, money, since, time } from '@/lib/format';

/**
 * Rider panel (PLAN §4.14): the deliveries given to me — call the customer, open the
 * address in Maps, picked up → delivered (collect the cash due) or failed — today's
 * finished ones, and the cash I hold until the cashier settles it. Refreshes itself.
 */
export default function RiderIndex({ me, view, deliveries, done, unsettled }) {
    const [dialog, setDialog] = useState(null); // { kind, delivery }
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});
    const now = useNow(30000);

    useLive('deliveries', () => router.reload({ only: ['deliveries', 'done', 'unsettled'] }));
    useLive('kitchen', () => router.reload({ only: ['deliveries'] }));

    const held = unsettled.reduce((n, d) => n + Number(d.cash_collected), 0);
    const close = () => {
        setDialog(null);
        setErrors({});
    };

    function move(delivery, action, reason = null) {
        router.put(route('rider.deliveries.status', delivery.id), { action, reason }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: close,
            onError: setErrors,
        });
    }

    if (!me) {
        return (
            <div className="waiter-page">
                <PageToolbar title="Deliveries" headTitle="Rider" />
                <EmptyState icon={Bike} title="No rider record">
                    Your login is not linked to a staff record of this branch — ask the manager.
                </EmptyState>
            </div>
        );
    }

    if (view === 'cash') {
        return (
            <div className="waiter-page">
                <PageToolbar title="My Cash" headTitle="Rider" />
                <div className="rider-cash">
                    <span className="rider-cash-label">Cash I hold</span>
                    <strong className="rider-cash-amount mono">{money(held)}</strong>
                    <span className="rider-cash-sub">
                        {unsettled.length} {unsettled.length === 1 ? 'delivery' : 'deliveries'} · hand it to the cashier to settle
                    </span>
                </div>

                {unsettled.length > 0 && (
                    <div className="rider-list">
                        {unsettled.map((d) => (
                            <div key={d.id} className="rider-row">
                                <span>
                                    <strong className="mono">{d.order.code}</strong> · {d.order.customer ?? d.address}
                                </span>
                                <span className="mono">{money(d.cash_collected)}</span>
                                <span className="cell-muted">Delivered {time(d.delivered_at)}</span>
                            </div>
                        ))}
                    </div>
                )}

                <div className="waiter-area-title">Delivered today</div>
                {done.length === 0 ? (
                    <p className="cell-muted">Nothing yet today.</p>
                ) : (
                    <div className="rider-list">
                        {done.map((d) => (
                            <div key={d.id} className="rider-row">
                                <span>
                                    <strong className="mono">{d.order.code}</strong> · {d.order.customer ?? d.address}
                                </span>
                                <Tag tone={d.status.tone}>{d.status.label}</Tag>
                                <span className="cell-muted">
                                    {Number(d.cash_collected) > 0 ? `${money(d.cash_collected)} cash${d.settled_at ? ' · settled' : ''}` : 'Paid before'}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className="waiter-page">
            <PageToolbar title={`Deliveries · ${me.name}`} headTitle="Rider" />

            {!me.rider && <div className="waiter-banner">You are not an active rider — deliveries can’t be given to you.</div>}
            {held > 0 && (
                <button type="button" className="rider-held" onClick={() => router.visit(route('rider.index', { view: 'cash' }))}>
                    <Wallet size={15} strokeWidth={1.5} /> You hold {money(held)} — settle it at the counter
                </button>
            )}

            {deliveries.length === 0 ? (
                <EmptyState icon={Bike} title="No deliveries for you">
                    New deliveries appear here — your phone vibrates.
                </EmptyState>
            ) : (
                <div className="rider-cards">
                    {deliveries.map((d) => (
                        <DeliveryCard
                            key={d.id}
                            delivery={d}
                            now={now}
                            processing={processing}
                            onOut={() => move(d, 'out')}
                            onDeliver={() => setDialog({ kind: 'deliver', delivery: d })}
                            onFail={() => setDialog({ kind: 'fail', delivery: d })}
                        />
                    ))}
                </div>
            )}

            {errors.delivery && <div className="pos-error">{errors.delivery}</div>}

            {dialog?.kind === 'fail' && (
                <FailDialog
                    delivery={dialog.delivery}
                    processing={processing}
                    error={errors.reason ?? errors.delivery}
                    onSubmit={(reason) => move(dialog.delivery, 'fail', reason)}
                    onClose={close}
                />
            )}
            <ConfirmDialog
                open={dialog?.kind === 'deliver'}
                onClose={close}
                title={dialog ? `Delivered ${dialog.delivery.order.code}?` : ''}
                message={
                    dialog?.delivery && Number(dialog.delivery.order.due) > 0
                        ? `Collect ${money(dialog.delivery.order.due)} cash from the customer.`
                        : 'The order is already paid — nothing to collect.'
                }
                confirmLabel="Delivered"
                processing={processing}
                onConfirm={() => move(dialog.delivery, 'deliver')}
            />
        </div>
    );
}

function DeliveryCard({ delivery: d, now, processing, onOut, onDeliver, onFail }) {
    const due = Number(d.order.due);
    const status = d.status.value;

    return (
        <div className={cx('rider-card', `is-${status}`)}>
            <div className="rider-card-head">
                <strong className="mono">{d.order.code}</strong>
                <Tag tone={d.status.tone}>{d.status.label}</Tag>
                {status === 'assigned' && <Tag tone={d.order.cooking ? 'warn' : 'accent'}>{d.order.cooking ? 'Cooking' : 'Ready'}</Tag>}
                <span className="rider-card-time">{since(d.assigned_at, now)}</span>
            </div>

            <div className="rider-card-who">
                <span className="rider-card-name">{d.order.customer ?? 'Customer'}</span>
                {d.phone && (
                    <a className="rider-link" href={telUrl(d.phone)}>
                        <Phone size={14} strokeWidth={1.5} /> {d.phone}
                    </a>
                )}
            </div>
            <a className="rider-address" href={mapsUrl(d.address)} target="_blank" rel="noreferrer">
                <MapPin size={15} strokeWidth={1.5} />
                <span>
                    {d.address}
                    {d.zone && <span className="cell-muted"> · {d.zone.name}</span>}
                </span>
                <Navigation size={14} strokeWidth={1.5} className="rider-address-go" />
            </a>

            {d.order.items.length > 0 && <div className="rider-card-items">{d.order.items.map((i) => `${i.quantity} × ${i.name}`).join(', ')}</div>}
            {d.order.notes && <div className="rider-card-notes">“{d.order.notes}”</div>}
            {d.failed_reason && <div className="rider-card-notes">Failed: {d.failed_reason} — bring it back to the shop if it can’t be delivered.</div>}

            <div className="rider-card-money">
                {due > 0 ? (
                    <>
                        Collect <strong className="mono">{money(due)}</strong> cash
                    </>
                ) : (
                    <>Paid — collect nothing</>
                )}
            </div>

            <div className="rider-card-actions">
                {status === 'assigned' && (
                    <button type="button" className="cart-pay" disabled={processing || d.order.cooking} onClick={onOut}>
                        <PackageCheck size={16} strokeWidth={1.5} /> {d.order.cooking ? 'Still cooking…' : 'Picked Up'}
                    </button>
                )}
                {(status === 'out_for_delivery' || status === 'failed') && (
                    <>
                        <button type="button" className="rider-fail" disabled={processing || status === 'failed'} onClick={onFail}>
                            <XCircle size={16} strokeWidth={1.5} /> Failed
                        </button>
                        <button type="button" className="cart-pay" disabled={processing} onClick={onDeliver}>
                            <CheckCircle2 size={16} strokeWidth={1.5} /> Delivered
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}
