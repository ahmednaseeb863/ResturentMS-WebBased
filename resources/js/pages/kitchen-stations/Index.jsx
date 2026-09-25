import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Monitor, Plus, Printer as PrinterIcon, Trash2 } from 'lucide-react';
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
    Select,
    StatusDot,
    Tag,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';

function StationDrawer({ station, printers, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(station?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: station?.name ?? '',
        printer: station?.printer?.id ?? '',
        has_screen: station?.has_screen ?? true,
        is_active: station?.is_active ?? true,
    });

    // keep an inactive printer that is still linked selectable
    const current = station?.printer;
    const options =
        current && !printers.some((p) => p.value === current.id)
            ? [...printers, { value: current.id, label: `${current.name} (inactive)` }]
            : printers;

    function submit() {
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('kitchen-stations.update', station.id), opts);
        else post(route('kitchen-stations.store'), opts);
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Kitchen Station — ${station.name}` : 'Add Kitchen Station'}
            footer={
                <>
                    {isEdit && can('kitchen-stations.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Station'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Name" required full error={errors.name} hint="e.g. Grill, Fryer, Drinks, Desserts">
                    <Input value={data.name} invalid={errors.name} onChange={(e) => setData('name', e.target.value)} autoFocus={!isEdit} />
                </Field>
                <Field
                    label="Ticket printer"
                    full
                    error={errors.printer}
                    hint={
                        options.length ? (
                            'Kitchen tickets (KOT) for this station print here'
                        ) : can('printers.index') ? (
                            <>
                                No kitchen printers yet — <Link href={route('printers.index')}>add one</Link>
                            </>
                        ) : (
                            'No kitchen printers yet'
                        )
                    }
                >
                    <Select
                        value={data.printer}
                        invalid={errors.printer}
                        placeholder="No printer"
                        options={options}
                        onChange={(e) => setData('printer', e.target.value)}
                    />
                </Field>
                <Field label="Kitchen screen" error={errors.has_screen}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.has_screen ? 'Shows on the KDS' : 'No screen'}</span>
                        <Toggle checked={data.has_screen} onChange={(v) => setData('has_screen', v)} label="Has a kitchen screen" />
                    </div>
                </Field>
                <Field label="Status" error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Active' : 'Inactive'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Station is active" />
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function KitchenStationsIndex({ stations, filters, counts, printers }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'kitchen station',
        destroy: 'kitchen-stations.destroy',
        restore: 'kitchen-stations.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [{ key: 'name', label: 'Station', className: 'cell-strong' }];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('kitchen-stations.restore') })]
        : [
              ...baseColumns,
              {
                  key: 'output',
                  label: 'Sends to',
                  render: (r) => (
                      <span className="tag-list">
                          {r.has_screen && (
                              <Tag tone="info">
                                  <Monitor size={10} /> Screen
                              </Tag>
                          )}
                          {r.printer && (
                              <Tag tone={r.printer.is_active ? 'accent' : 'neutral'}>
                                  <PrinterIcon size={10} /> {r.printer.name}
                              </Tag>
                          )}
                          {!r.has_screen && !r.printer && <span className="cell-muted">Nowhere yet</span>}
                      </span>
                  ),
              },
              { key: 'categories_count', label: 'Categories', align: 'right' },
              { key: 'items_count', label: 'Own items', align: 'right' },
              {
                  key: 'status',
                  label: 'Status',
                  render: (r) => (
                      <StatusDot status={r.is_active ? 'active' : 'inactive'}>{r.is_active ? 'Active' : 'Inactive'}</StatusDot>
                  ),
              },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Kitchen Stations"
                primary={
                    can('kitchen-stations.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Station
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>{counts.active} stations · categories send their items to a station</span>
            </PageStatus>

            <TrashTabs
                value={filters.tab}
                onChange={(tab) => setQuery('tab', tab)}
                counts={counts}
                canRestore={can('kitchen-stations.restore')}
            />

            <FilterBar count={`${stations.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search stations…" />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={stations.data}
                meta={stations.meta}
                noun="stations"
                empty={inTrash ? 'Trash is empty' : 'No kitchen stations yet'}
                onRowClick={!inTrash && can('kitchen-stations.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <StationDrawer
                    key={editing.id ?? 'new'}
                    station={editing}
                    printers={printers}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
