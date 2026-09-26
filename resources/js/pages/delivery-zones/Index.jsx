import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import {
    Button,
    DataTable,
    Drawer,
    Field,
    FilterBar,
    FormGrid,
    Input,
    PageBody,
    PageStatus,
    PageToolbar,
    SearchInput,
    StatusDot,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';
import { money } from '@/lib/format';

function ZoneDrawer({ zone, rules, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(zone?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: zone?.name ?? '',
        fee: zone?.fee ?? String(rules.default_fee),
        min_order_amount: zone?.min_order_amount ?? '',
        sort_order: zone?.sort_order ?? 0,
        is_active: zone?.is_active ?? true,
    });

    function submit() {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('delivery-zones.update', zone.id), options);
        else post(route('delivery-zones.store'), options);
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Zone — ${zone.name}` : 'Add Delivery Zone'}
            footer={
                <>
                    {isEdit && can('delivery-zones.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Zone'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Name" required full error={errors.name} hint="e.g. Gulberg, DHA Phase 5, Within 3 km">
                    <Input value={data.name} invalid={errors.name} onChange={(e) => setData('name', e.target.value)} autoFocus={!isEdit} />
                </Field>
                <Field label="Delivery fee" required error={errors.fee}>
                    <Input mono type="number" min="0" step="0.01" inputMode="decimal" value={data.fee} invalid={errors.fee} onChange={(e) => setData('fee', e.target.value)} />
                </Field>
                <Field
                    label="Minimum order"
                    error={errors.min_order_amount}
                    hint={`Empty = the Delivery setting (${rules.min_order_amount > 0 ? money(rules.min_order_amount) : 'no minimum'})`}
                >
                    <Input
                        mono
                        type="number"
                        min="0"
                        step="0.01"
                        inputMode="decimal"
                        value={data.min_order_amount ?? ''}
                        invalid={errors.min_order_amount}
                        onChange={(e) => setData('min_order_amount', e.target.value)}
                    />
                </Field>
                <Field label="Sort order" error={errors.sort_order}>
                    <Input mono type="number" min="0" value={data.sort_order} invalid={errors.sort_order} onChange={(e) => setData('sort_order', e.target.value)} />
                </Field>
                <Field label="Status" error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Active' : 'Inactive'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Zone is active" />
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

/** Delivery zones of the branch (PLAN §4.14): fee and minimum per zone, picked at the POS. */
export default function DeliveryZonesIndex({ zones, filters, counts, rules }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'delivery zone',
        destroy: 'delivery-zones.destroy',
        restore: 'delivery-zones.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [{ key: 'name', label: 'Zone', className: 'cell-strong' }];
    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('delivery-zones.restore') })]
        : [
              ...baseColumns,
              { key: 'fee', label: 'Fee', align: 'right', className: 'mono', render: (r) => money(r.fee) },
              {
                  key: 'min',
                  label: 'Minimum Order',
                  align: 'right',
                  className: 'mono',
                  render: (r) => (r.min_order_amount !== null ? money(r.min_order_amount) : <span className="cell-muted">Default</span>),
              },
              { key: 'deliveries', label: 'Deliveries', align: 'right', className: 'mono', render: (r) => r.deliveries_count ?? 0 },
              {
                  key: 'status',
                  label: 'Status',
                  render: (r) => <StatusDot status={r.is_active ? 'active' : 'inactive'}>{r.is_active ? 'Active' : 'Inactive'}</StatusDot>,
              },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Delivery Zones"
                primary={
                    can('delivery-zones.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Zone
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {counts.active} zones ·{' '}
                    {rules.use_zones ? 'zones are on — the POS asks for the zone' : `zones are off — every delivery pays ${money(rules.default_fee)} (Settings → Delivery)`}
                </span>
            </PageStatus>

            <TrashTabs value={filters.tab} onChange={(tab) => setQuery('tab', tab)} counts={counts} canRestore={can('delivery-zones.restore')} />

            <FilterBar count={`${zones.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search zones…" />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={zones.data}
                meta={zones.meta}
                noun="zones"
                empty={inTrash ? 'Trash is empty' : 'No delivery zones yet'}
                onRowClick={!inTrash && can('delivery-zones.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <ZoneDrawer key={editing.id ?? 'new'} zone={editing} rules={rules} onClose={() => setEditing(null)} onTrash={() => trash.ask(editing)} />
            )}
            {trash.dialog}
        </PageBody>
    );
}
