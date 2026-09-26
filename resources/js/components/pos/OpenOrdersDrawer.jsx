import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Drawer, EmptyState, SearchInput, Tabs, Tag } from '@/components/ui';
import { cx, dateTime, money } from '@/lib/format';

/** Held and unpaid orders of the branch — pick one to open it on the POS. */
export default function OpenOrdersDrawer({ orders, currentId, onClose }) {
    const [tab, setTab] = useState('all');
    const [search, setSearch] = useState('');

    const q = search.trim().toLowerCase();
    const shown = orders.filter(
        (o) =>
            (tab === 'all' || (tab === 'held' ? o.is_draft : !o.is_draft && o.type.value === tab)) &&
            (!q || [o.code, o.label, o.customer?.phone].some((v) => v?.toLowerCase().includes(q))),
    );
    const count = (fn) => orders.filter(fn).length;

    return (
        <Drawer open onClose={onClose} title={`Open Orders (${orders.length})`}>
            <Tabs
                className="pos-open-tabs"
                value={tab}
                onChange={setTab}
                tabs={[
                    { key: 'all', label: 'All', count: orders.length },
                    { key: 'held', label: 'Held', count: count((o) => o.is_draft) },
                    { key: 'dine_in', label: 'Dine-in', count: count((o) => !o.is_draft && o.type.value === 'dine_in') },
                    { key: 'takeaway', label: 'Takeaway', count: count((o) => !o.is_draft && o.type.value === 'takeaway') },
                    { key: 'delivery', label: 'Delivery', count: count((o) => !o.is_draft && o.type.value === 'delivery') },
                ]}
            />
            <div className="pos-open-search">
                <SearchInput value={search} onChange={setSearch} placeholder="Order no., table, customer…" />
            </div>

            {shown.length === 0 ? (
                <EmptyState title="No open orders">Held and unpaid orders show up here.</EmptyState>
            ) : (
                <div className="pos-open-list">
                    {shown.map((o) => (
                        <Link
                            key={o.id}
                            href={route('pos.index', { order: o.id })}
                            className={cx('pos-open-row', o.id === currentId && 'on')}
                        >
                            <div className="pos-open-main">
                                <span className="pos-open-code">{o.is_draft ? 'Held' : o.code}</span>
                                <span>{o.label}</span>
                                <Tag tone={o.status.tone}>{o.status.label}</Tag>
                            </div>
                            <div className="pos-open-sub">
                                <span>
                                    {o.type.label} · {o.item_count} item{o.item_count === 1 ? '' : 's'} · {dateTime(o.created_at)}
                                </span>
                                <span className="mono">{money(o.grand_total)}</span>
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </Drawer>
    );
}
