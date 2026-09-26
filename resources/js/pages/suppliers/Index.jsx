import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Button, DataTable, FilterBar, PageBody, PageStatus, PageToolbar, SearchInput, StatusDot, TrashTabs, trashColumns } from '@/components/ui';
import SupplierDrawer from '@/components/inventory/SupplierDrawer';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';
import { money } from '@/lib/format';

/** Suppliers (shared by all branches) with what this branch owes each one. */
export default function SuppliersIndex({ suppliers, filters, counts }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({ noun: 'supplier', destroy: 'suppliers.destroy', restore: 'suppliers.restore', onDone: () => setEditing(null) });
    const inTrash = filters.tab === 'trash';

    const base = [
        {
            key: 'name',
            label: 'Supplier',
            render: (r) => (
                <>
                    <span className="cell-strong">{r.name}</span>
                    {r.contact_person && <span className="cell-sub">{r.contact_person}</span>}
                </>
            ),
        },
    ];
    const columns = inTrash
        ? [...base, ...trashColumns({ onRestore: trash.restore, canRestore: can('suppliers.restore') })]
        : [
              ...base,
              { key: 'phone', label: 'Phone', className: 'mono', render: (r) => r.phone ?? '—' },
              { key: 'purchases', label: 'Purchases', align: 'right', className: 'mono', render: (r) => r.purchases_count ?? 0 },
              {
                  key: 'balance',
                  label: 'Owed',
                  align: 'right',
                  render: (r) =>
                      r.balance > 0 ? (
                          <strong className="mono">{money(r.balance)}</strong>
                      ) : r.balance < 0 ? (
                          <span className="mono cell-muted">{money(-r.balance)} advance</span>
                      ) : (
                          <span className="cell-muted">—</span>
                      ),
              },
              { key: 'status', label: 'Status', render: (r) => <StatusDot status={r.is_active ? 'active' : 'inactive'}>{r.is_active ? 'Active' : 'Inactive'}</StatusDot> },
          ];

    const owed = suppliers.data.reduce((n, s) => n + Math.max(0, s.balance ?? 0), 0);

    return (
        <PageBody>
            <PageToolbar
                title="Suppliers"
                primary={
                    can('suppliers.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Supplier
                        </Button>
                    )
                }
            >
                {can('purchases.create') && <Button href={route('purchases.create')}>Receive Purchase</Button>}
            </PageToolbar>
            <PageStatus>
                <span>
                    {counts.active} suppliers · {money(owed)} owed on this page
                </span>
            </PageStatus>

            <TrashTabs value={filters.tab} onChange={(tab) => setQuery('tab', tab)} counts={counts} canRestore={can('suppliers.restore')} />
            <FilterBar count={`${suppliers.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Name, contact, phone…" />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={suppliers.data}
                meta={suppliers.meta}
                noun="suppliers"
                empty={inTrash ? 'Trash is empty' : 'No suppliers yet'}
                onRowClick={!inTrash && can('suppliers.show') ? (r) => router.visit(route('suppliers.show', r.id)) : !inTrash && can('suppliers.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && <SupplierDrawer key={editing.id ?? 'new'} supplier={editing} onClose={() => setEditing(null)} onTrash={() => trash.ask(editing)} />}
            {trash.dialog}
        </PageBody>
    );
}
