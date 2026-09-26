import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { LayoutDashboard, RefreshCw } from 'lucide-react';
import { Button, ChartBox, EmptyState, PageStatus, PageToolbar, StatCard, StatGrid, Tag } from '@/components/ui';
import useCan from '@/hooks/useCan';
import { request } from '@/lib/http';
import { cx, date, money, number, qty } from '@/lib/format';

const TYPE_LABELS = { dine_in: 'dine-in', takeaway: 'takeaway', delivery: 'delivery' };

/** Bars scaled to the biggest value (pos-react `.bars`); the value shows on hover. */
function Bars({ data, empty = 'No sales yet' }) {
    const max = Math.max(0, ...data.map((d) => d.value));
    if (!data.length || max <= 0) return <div className="dash-empty">{empty}</div>;

    return (
        <>
            <div className="bars">
                {data.map((d) => (
                    <div
                        key={d.label + (d.date ?? '')}
                        className="bar"
                        title={`${d.date ? date(d.date) : d.label} — ${money(d.value)}`}
                        // data-driven height: the one allowed inline style (a CSS variable)
                        // eslint-disable-next-line react/forbid-dom-props
                        style={{ '--bar-h': `${Math.max(2, Math.round((d.value / max) * 100))}%` }}
                    />
                ))}
            </div>
            <div className="bar-labels">
                {data.map((d) => (
                    <span key={d.label + (d.date ?? '')}>{d.label}</span>
                ))}
            </div>
        </>
    );
}

function ListRows({ rows, empty }) {
    if (!rows.length) return <div className="dash-empty">{empty}</div>;

    return (
        <div className="dash-list">
            {rows.map((r) => (
                <div key={r.key} className="dash-list-row">
                    <div className="dash-list-main">
                        <span className="dash-list-name">{r.name}</span>
                        {r.sub && <span className="dash-list-sub">{r.sub}</span>}
                    </div>
                    <span className={cx('dash-list-value mono', r.tone && `dash-tone-${r.tone}`)}>{r.value}</span>
                </div>
            ))}
        </div>
    );
}

/** Alerts that need someone's attention, most urgent first. */
function alertsOf(s, can) {
    const list = [];
    if (s.stock.out > 0) list.push({ tag: 'Out of stock', tone: 'danger', text: `${s.stock.out} item${s.stock.out > 1 ? 's' : ''} ran out`, href: can('low-stock.index') && route('low-stock.index') });
    if (s.stock.low > s.stock.out) list.push({ tag: 'Low stock', tone: 'accent', text: `${s.stock.low - s.stock.out} item(s) at or below the alert level`, href: can('low-stock.index') && route('low-stock.index') });
    if (s.orders.bills > 0) list.push({ tag: 'Bill', tone: 'warn', text: `${s.orders.bills} table(s) asked for the bill`, href: can('orders.index') && route('orders.index') });
    if (s.kitchen.oldest_minutes >= 20) list.push({ tag: 'Kitchen', tone: 'warn', text: `Oldest item has waited ${s.kitchen.oldest_minutes} min`, href: can('kitchen.index') && route('kitchen.index') });
    if (s.riders.unassigned > 0) list.push({ tag: 'Delivery', tone: 'warn', text: `${s.riders.unassigned} delivery(ies) waiting for a rider`, href: can('deliveries.index') && route('deliveries.index') });
    s.shifts.filter((sh) => sh.overdue).forEach((sh) => list.push({ tag: 'Shift', tone: 'warn', text: `${sh.code} · ${sh.counter} is past its end time`, href: can('shifts.index') && route('shifts.index') }));
    if (s.riders.cash_held > 0) list.push({ tag: 'Rider cash', tone: 'neutral', text: `${money(s.riders.cash_held)} with riders, not settled`, href: can('riders.index') && route('riders.index') });
    if (s.kitchen.pending_consumption > 0)
        list.push({ tag: 'Consumption', tone: 'neutral', text: `${s.kitchen.pending_consumption} line(s) waiting for raw-material confirmation`, href: can('consumptions.pending') && route('consumptions.pending') });
    if (s.reservations.next[0]) {
        const r = s.reservations.next[0];
        list.push({ tag: 'Reservation', tone: 'info', text: `${r.time} — ${r.guest}, ${r.party} guests${r.table ? ` · ${r.table}` : ''}`, href: can('reservations.index') && route('reservations.index') });
    }
    if (s.supplier_dues > 0) list.push({ tag: 'Suppliers', tone: 'neutral', text: `${money(s.supplier_dues)} owed to suppliers`, href: can('suppliers.index') && route('suppliers.index') });
    return list;
}

/**
 * Dashboard (PLAN §4.19): today's figures for the branch or all branches. Refreshes itself
 * every minute by asking `dashboard.stats` again (polling — no WebSockets); paused while the
 * tab is hidden and refreshed as soon as it is visible again.
 */
export default function Dashboard({ stats: initial, branch, branchLabel, branchOptions, refreshSeconds }) {
    const can = useCan();
    const { auth } = usePage().props;
    const [stats, setStats] = useState(initial);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const lastLoad = useRef(0);

    const refresh = useCallback(async () => {
        if (!initial) return;
        setLoading(true);
        try {
            setStats(await request(route('dashboard.stats', branch ? { branch } : {})));
            setFailed(false);
            lastLoad.current = Date.now();
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    }, [branch, initial]);

    useEffect(() => {
        if (!initial) return undefined;
        lastLoad.current = Date.now();
        const timer = setInterval(() => {
            if (!document.hidden) refresh();
        }, refreshSeconds * 1000);
        const onVisible = () => {
            if (!document.hidden && Date.now() - lastLoad.current >= refreshSeconds * 1000) refresh();
        };
        document.addEventListener('visibilitychange', onVisible);
        return () => {
            clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [initial, refresh, refreshSeconds]);

    const branchPicker = branchOptions.length > 0 && (
        <select
            className="fselect"
            aria-label="Branch"
            value={branch}
            onChange={(e) => router.get(route('dashboard'), { branch: e.target.value }, { preserveScroll: true })}
        >
            {branchOptions.map((o) => (
                <option key={o.value} value={o.value}>
                    {o.label}
                </option>
            ))}
        </select>
    );

    if (!stats) {
        return (
            <div className="p-5 dashboard">
                <PageToolbar title="Dashboard" />
                <EmptyState icon={LayoutDashboard} title={`Welcome, ${auth?.user?.name ?? ''}`}>
                    Use the menu on the left to get to your screens.
                </EmptyState>
            </div>
        );
    }

    const s = stats;
    const types = Object.entries(s.sales.by_type)
        .map(([type, v]) => `${v.orders} ${TYPE_LABELS[type] ?? type}`)
        .join(' · ');
    const alerts = alertsOf(s, can);

    return (
        <div className="p-5 dashboard">
            <PageToolbar title="Dashboard">
                {branchPicker}
                <Button icon={RefreshCw} onClick={refresh} disabled={loading} className={cx(loading && 'is-spinning')}>
                    Refresh
                </Button>
            </PageToolbar>
            <PageStatus>
                <span>
                    {branchLabel} · business day {date(s.business_date)} · updated {s.updated_at}
                    {failed ? ' · could not refresh, trying again' : ` · refreshes every ${refreshSeconds} s`}
                </span>
            </PageStatus>

            <StatGrid>
                <StatCard
                    label="Today's Sales"
                    value={money(s.sales.total)}
                    sub={s.sales.change === null ? `Yesterday ${money(s.sales.yesterday)}` : `${s.sales.change >= 0 ? '↑' : '↓'} ${Math.abs(s.sales.change)}% vs yesterday`}
                    tone={s.sales.change !== null && s.sales.change < 0 ? 'danger' : 'accent'}
                />
                <StatCard label="Orders" value={number(s.sales.orders)} sub={types || 'No orders yet'} tone="accent" />
                <StatCard label="Average Order" value={money(s.sales.average)} sub={`${number(s.sales.guests)} guests · ${money(s.sales.discounts)} discounts`} tone="neutral" />
                <StatCard label="Cash in Drawers" value={money(s.cash_in_drawers)} sub={`${s.shifts.length} open shift${s.shifts.length === 1 ? '' : 's'}`} tone="neutral" />
            </StatGrid>

            <StatGrid>
                <StatCard label="Open Orders" value={number(s.orders.open)} sub={`${s.orders.cooking} cooking · ${s.orders.ready} ready · ${s.orders.held} held`} tone="neutral" />
                <StatCard
                    label="Tables"
                    value={`${s.tables.occupied} / ${s.tables.total}`}
                    sub={`${s.tables.reserved} reserved · ${s.tables.cleaning} cleaning · ${s.orders.bills} bill asked`}
                    tone="neutral"
                />
                <StatCard
                    label="Kitchen Queue"
                    value={number(s.kitchen.queued + s.kitchen.cooking)}
                    sub={s.kitchen.oldest_minutes !== null ? `Oldest waiting ${s.kitchen.oldest_minutes} min · ${s.kitchen.ready} ready` : `${s.kitchen.ready} ready to serve`}
                    tone={s.kitchen.oldest_minutes >= 20 ? 'danger' : 'neutral'}
                />
                <StatCard label="Rider Cash" value={money(s.riders.cash_held)} sub={`${s.riders.out} on the way · ${s.riders.unassigned} waiting for a rider`} tone="neutral" />
            </StatGrid>

            <StatGrid>
                <StatCard label="Payments Received" value={money(s.payments.received)} sub={`Cash ${money(s.payments.cash)} · Bank ${money(s.payments.bank)}`} tone="accent" />
                <StatCard label="Unpaid on Open Orders" value={money(s.payments.unpaid)} sub={s.payments.refunds > 0 ? `${money(s.payments.refunds)} refunded today` : 'No refunds today'} tone="neutral" />
                <StatCard label="Expenses Today" value={money(s.expenses_today)} sub={`${money(s.sales.tax)} tax collected`} tone="neutral" />
                <StatCard
                    label="Low Stock Items"
                    value={number(s.stock.low)}
                    sub={s.stock.out > 0 ? `${s.stock.out} out of stock` : `${s.reservations.today} reservations · ${s.reservations.guests} guests`}
                    tone={s.stock.out > 0 ? 'danger' : 'neutral'}
                />
            </StatGrid>

            <div className="dash-row">
                <ChartBox title="Sales — Last 7 Days" actions={can('reports.sales') && <Link className="dash-link" href={route('reports.sales', 'daily-sales')}>Report</Link>}>
                    <Bars data={s.week} />
                </ChartBox>
                <ChartBox title="Open Shifts" actions={can('shifts.index') && <Link className="dash-link" href={route('shifts.index')}>Shifts</Link>}>
                    <ListRows
                        empty="No shift is open"
                        rows={s.shifts.map((sh) => ({
                            key: sh.code + sh.branch,
                            name: `${sh.code} · ${sh.counter}`,
                            sub: `${sh.cashier} · since ${sh.opened}${branch === 'all' && sh.branch ? ` · ${sh.branch}` : ''}${sh.overdue ? ' · overdue' : ''}`,
                            value: money(sh.expected),
                            tone: sh.overdue ? 'danger' : undefined,
                        }))}
                    />
                </ChartBox>
            </div>

            <div className="dash-row">
                <ChartBox title="Sales by Hour — Today" actions={can('reports.sales') && <Link className="dash-link" href={route('reports.sales', 'hourly')}>Report</Link>}>
                    <Bars data={s.hourly} />
                </ChartBox>
                <ChartBox title="Top Items Today" actions={can('reports.sales') && <Link className="dash-link" href={route('reports.sales', 'items')}>Report</Link>}>
                    <ListRows empty="Nothing sold yet" rows={s.top_items.map((i) => ({ key: i.name, name: i.name, sub: money(i.total), value: `×${i.qty}` }))} />
                </ChartBox>
            </div>

            <div className="dash-row">
                <ChartBox title="Recent Activity">
                    {s.activity.length ? (
                        <div className="alist">
                            {s.activity.map((a, i) => (
                                <div key={i} className="alist-item">
                                    <div className={cx('alist-dot', `alist-dot-${a.tone}`)} />
                                    <div>
                                        <div className="alist-text">{a.text}</div>
                                        <div className="alist-time">{a.time}</div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="dash-empty">Nothing yet today</div>
                    )}
                </ChartBox>
                <ChartBox title="Low Stock" actions={can('low-stock.index') && <Link className="dash-link" href={route('low-stock.index')}>All</Link>}>
                    <ListRows
                        empty="Stock is fine"
                        rows={s.stock.items.map((i) => ({
                            key: i.kind + i.name,
                            name: i.name,
                            sub: `${i.kind} · alert at ${qty(i.alert, i.unit)}`,
                            value: qty(i.stock, i.unit),
                            tone: i.stock <= 0 ? 'danger' : 'warn',
                        }))}
                    />
                </ChartBox>
            </div>

            {alerts.length > 0 && (
                <div className="alert-row">
                    {alerts.map((a) => {
                        const body = (
                            <>
                                <Tag tone={a.tone}>{a.tag}</Tag>
                                <span className="alert-card-text">{a.text}</span>
                            </>
                        );
                        return a.href ? (
                            <Link key={a.tag + a.text} href={a.href} className="alert-card alert-card-link">
                                {body}
                            </Link>
                        ) : (
                            <div key={a.tag + a.text} className="alert-card">
                                {body}
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
