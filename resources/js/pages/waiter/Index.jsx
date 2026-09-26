import { Link, router, usePage } from '@inertiajs/react';
import { Clock, Users } from 'lucide-react';
import { PageToolbar, Tag } from '@/components/ui';
import useLive from '@/hooks/useLive';
import useNow from '@/hooks/useNow';
import { cx, money, since } from '@/lib/format';

const VIEWS = [
    { key: 'all', label: 'All' },
    { key: 'mine', label: 'Mine' },
    { key: 'ready', label: 'Ready' },
    { key: 'free', label: 'Free' },
];

/**
 * Waiter app home (PLAN §4.11): every table by area with its live status — the order on
 * it, items ready to take out, still cooking, bill asked for. Refreshes itself when an
 * order / table changes or the kitchen moves (polling). Tap a table to take its order.
 */
export default function WaiterIndex({ tables, shiftOpen }) {
    const { url } = usePage();
    const view = new URLSearchParams(url.split('?')[1] ?? '').get('view') ?? 'all';
    const now = useNow(30000);

    useLive('floor', () => router.reload({ only: ['tables', 'shiftOpen'] }));
    useLive('kitchen', () => router.reload({ only: ['tables'] }));

    const shown = tables.filter((t) => {
        if (view === 'mine') return t.order?.mine;
        if (view === 'ready') return t.order?.ready > 0;
        if (view === 'free') return !t.order && t.status.value === 'available';
        return true;
    });
    const areas = [...new Set(shown.map((t) => t.area ?? 'Tables'))];

    const busy = tables.filter((t) => t.order).length;
    const ready = tables.filter((t) => t.order?.ready > 0).length;
    const bills = tables.filter((t) => t.order?.bill_requested).length;

    return (
        <div className="waiter-page">
            <PageToolbar title="Tables" headTitle="Waiter" />

            {!shiftOpen && <div className="waiter-banner">No cash counter is open — orders can’t be sent until the cashier opens a shift.</div>}

            <div className="waiter-summary">
                <span>
                    <strong>{tables.length - busy}</strong> free
                </span>
                <span>
                    <strong>{busy}</strong> seated
                </span>
                <span className={cx(ready > 0 && 'is-hot')}>
                    <strong>{ready}</strong> ready to serve
                </span>
                {bills > 0 && (
                    <span>
                        <strong>{bills}</strong> asked for the bill
                    </span>
                )}
            </div>

            <div className="pos-cats waiter-views" role="tablist" aria-label="Show">
                {VIEWS.map((v) => (
                    <Link
                        key={v.key}
                        href={route('waiter.index', v.key === 'all' ? {} : { view: v.key })}
                        role="tab"
                        aria-selected={view === v.key}
                        className={cx('pos-cat', view === v.key && 'active')}
                        preserveScroll
                    >
                        {v.label}
                    </Link>
                ))}
            </div>

            {shown.length === 0 && (
                <p className="pos-empty">
                    {tables.length === 0
                        ? 'No active tables — ask the manager to add them.'
                        : view === 'mine'
                          ? 'None of your tables have an open order.'
                          : view === 'ready'
                            ? 'Nothing is waiting to be served.'
                            : 'No free tables right now.'}
                </p>
            )}

            {areas.map((area) => (
                <section key={area} className="waiter-area">
                    <h2 className="waiter-area-title">{area}</h2>
                    <div className="waiter-grid">
                        {shown
                            .filter((t) => (t.area ?? 'Tables') === area)
                            .map((t) => (
                                <TableCard key={t.id} table={t} now={now} />
                            ))}
                    </div>
                </section>
            ))}
        </div>
    );
}

function TableCard({ table, now }) {
    const o = table.order;

    return (
        <Link
            href={route('waiter.table', table.id)}
            className={cx('pos-table', 'wt-card', `pt-${table.status.value}`, o?.mine && 'is-mine', o?.ready > 0 && 'has-ready')}
        >
            <span className="wt-head">
                <span className="pos-table-name">{table.name}</span>
                <span className="wt-seats">
                    <Users size={11} strokeWidth={1.5} />
                    {o?.guests ?? table.capacity}
                </span>
            </span>

            {o ? (
                <>
                    <span className="wt-order mono">
                        {o.is_draft ? 'Held' : o.code} · {money(o.total)}
                    </span>
                    <span className="pos-table-sub">
                        {o.waiter ?? 'No waiter'} · <Clock size={10} strokeWidth={1.5} /> {since(o.placed_at, now)}
                    </span>
                    <span className="wt-tags">
                        {o.ready > 0 && <Tag tone="info">{o.ready} ready</Tag>}
                        {o.cooking > 0 && <Tag tone="warn">{o.cooking} cooking</Tag>}
                        {o.bill_requested && <Tag tone="accent">Bill</Tag>}
                        {o.is_draft && <Tag>Held on POS</Tag>}
                    </span>
                </>
            ) : (
                <span className="pos-table-sub">
                    {table.capacity} seats · {table.status.label}
                </span>
            )}
        </Link>
    );
}
