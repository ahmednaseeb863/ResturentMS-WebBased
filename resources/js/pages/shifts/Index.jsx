import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Clock, XCircle } from 'lucide-react';
import {
    Button,
    DataTable,
    FilterBar,
    FilterSelect,
    PageBody,
    PageStatus,
    PageToolbar,
    StatCard,
    StatusDot,
} from '@/components/ui';
import OpenShiftDialog from '@/components/shifts/OpenShiftDialog';
import ShiftBanner from '@/components/shifts/ShiftBanner';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import { date, dateTime, money, number } from '@/lib/format';

/** "+200" / "−50" / "—" coloured like pos-react's Difference column. */
export function Difference({ value }) {
    if (value === null || value === undefined) return <span className="cell-muted">—</span>;
    const d = Number(value);
    if (d === 0) return <span className="cash-plus">—</span>;
    return <span className={d > 0 ? 'cash-plus' : 'cash-minus'}>{d > 0 ? `+${number(d)}` : `−${number(Math.abs(d))}`}</span>;
}

/** Shift Management (pos-react Shifts): open shifts, stats, history. */
export default function ShiftsIndex({ shifts, openShifts, filters, stats, statuses, counterOptions, myShift, ...openForm }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [opening, setOpening] = useState(false);
    const mine = openShifts.find((s) => s.id === myShift);

    const columns = [
        { key: 'code', label: 'Shift', className: 'mono shift-code', render: (r) => r.code },
        { key: 'business_date', label: 'Business Day', render: (r) => date(r.business_date) },
        {
            key: 'counter',
            label: 'Counter',
            render: (r) => (
                <>
                    {r.counter?.name}
                    {r.type && <span className="cell-sub">{r.type.name}</span>}
                </>
            ),
        },
        { key: 'cashier', label: 'Cashier', render: (r) => r.opened_by?.name },
        { key: 'opened', label: 'Open', className: 'mono cell-muted', render: (r) => dateTime(r.opened_at) },
        {
            key: 'closed',
            label: 'Close',
            className: 'mono cell-muted',
            render: (r) => (r.closed_at ? dateTime(r.closed_at) : '—'),
        },
        { key: 'opening_cash', label: 'Opening Cash', align: 'right', className: 'mono', render: (r) => number(r.opening_cash) },
        {
            key: 'expected',
            label: 'Expected',
            align: 'right',
            className: 'mono',
            render: (r) => (r.cash_visible ? number(r.expected_cash) : <span className="cell-muted">Blind</span>),
        },
        {
            key: 'counted',
            label: 'Counted',
            align: 'right',
            className: 'mono cell-strong',
            render: (r) => (r.counted_cash !== null ? number(r.counted_cash) : '—'),
        },
        {
            key: 'difference',
            label: 'Difference',
            align: 'right',
            className: 'mono cell-strong',
            render: (r) => <Difference value={r.difference} />,
        },
        {
            key: 'status',
            label: 'Status',
            render: (r) => (
                <StatusDot status={r.is_open ? 'active' : 'inactive'}>
                    {r.is_open ? (r.is_overdue ? 'Open · overdue' : 'Open') : 'Closed'}
                </StatusDot>
            ),
        },
    ];

    const primary = mine
        ? can('shifts.close') && (
              <Button variant="danger" icon={XCircle} href={route('shifts.show', { shift: mine.id, close: 1 })}>
                  Close Shift
              </Button>
          )
        : can('shifts.open') && (
              <Button variant="primary" icon={Clock} onClick={() => setOpening(true)}>
                  Open Shift
              </Button>
          );

    const range = filters.from || filters.to ? 'in range' : 'all time';

    return (
        <PageBody>
            <PageToolbar title="Shift Management" primary={primary} />
            <PageStatus>
                <span>
                    {mine ? `Your shift ${mine.code} is open since ${dateTime(mine.opened_at)}` : 'You have no open shift'}
                </span>
                <span>{stats.open} open now</span>
            </PageStatus>

            {openShifts.map((s) => (
                <ShiftBanner key={s.id} shift={s} href={route('shifts.show', s.id)} />
            ))}

            <div className="pay-stat-grid">
                <StatCard label={`Closed Shifts (${range})`} value={stats.closed} />
                <StatCard label="Open Now" value={stats.open} />
                <StatCard label="Cash Over" value={money(stats.over)} />
                <StatCard
                    label="Cash Short"
                    value={stats.short < 0 ? `−${money(Math.abs(stats.short))}` : money(0)}
                    sub={`${stats.off} of ${stats.closed} shifts off`}
                    tone={stats.off ? 'danger' : 'neutral'}
                />
            </div>

            <FilterBar count={`${shifts.meta.total} shifts`}>
                <FilterSelect
                    label="Status"
                    value={query.status}
                    onChange={(v) => setQuery('status', v)}
                    options={[{ value: '', label: 'All' }, ...statuses]}
                />
                <FilterSelect
                    label="Counter"
                    value={query.counter}
                    onChange={(v) => setQuery('counter', v)}
                    options={[{ value: '', label: 'All' }, ...counterOptions]}
                />
                <span className="flabel">From:</span>
                <input type="date" className="fselect ledger-date" value={query.from} onChange={(e) => setQuery('from', e.target.value)} />
                <span className="flabel">To:</span>
                <input type="date" className="fselect ledger-date" value={query.to} onChange={(e) => setQuery('to', e.target.value)} />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={shifts.data}
                meta={shifts.meta}
                noun="shifts"
                empty="No shifts yet"
                onRowClick={(r) => router.visit(route('shifts.show', r.id))}
                stack
            />

            {opening && <OpenShiftDialog {...openForm} onClose={() => setOpening(false)} />}
        </PageBody>
    );
}
