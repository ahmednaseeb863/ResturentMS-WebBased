import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { LayoutGrid, MapPin, Plus, Trash2 } from 'lucide-react';
import {
    Button,
    DataTable,
    Drawer,
    Field,
    FilterBar,
    FilterSelect,
    FormGrid,
    Input,
    PageBody,
    PageStatus,
    PageToolbar,
    SearchInput,
    Select,
    StatusDot,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import { TableStatusTag } from '@/pages/tables/Floor';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';

function TableDrawer({ table, areas, shapes, defaultArea, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(table?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: table?.name ?? '',
        area: table?.area?.id ?? defaultArea ?? areas[0]?.value ?? '',
        capacity: table?.capacity ?? 4,
        shape: table?.shape ?? 'square',
        is_active: table?.is_active ?? true,
    });

    function submit() {
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('tables.update', table.id), opts);
        else post(route('tables.store'), opts);
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Table — ${table.name}` : 'Add Table'}
            footer={
                <>
                    {isEdit && can('tables.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Table'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Table name / number" required error={errors.name}>
                    <Input value={data.name} invalid={errors.name} placeholder="T1" onChange={(e) => setData('name', e.target.value)} autoFocus={!isEdit} />
                </Field>
                <Field
                    label="Area"
                    required
                    error={errors.area}
                    hint={
                        !areas.length &&
                        (can('areas.index') ? (
                            <>
                                No areas yet — <Link href={route('areas.index')}>add one</Link>
                            </>
                        ) : (
                            'No areas yet'
                        ))
                    }
                >
                    <Select
                        value={data.area}
                        invalid={errors.area}
                        placeholder="Pick an area…"
                        options={areas}
                        onChange={(e) => setData('area', e.target.value)}
                    />
                </Field>
                <Field label="Seats" required error={errors.capacity}>
                    <Input
                        type="number"
                        min="1"
                        max="50"
                        value={data.capacity}
                        invalid={errors.capacity}
                        onChange={(e) => setData('capacity', e.target.value)}
                    />
                </Field>
                <Field label="Shape" required error={errors.shape} hint="How it looks on the floor plan">
                    <Select value={data.shape} invalid={errors.shape} options={shapes} onChange={(e) => setData('shape', e.target.value)} />
                </Field>
                <Field label="Status" full error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'In use' : 'Not in use (hidden from POS and waiters)'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Table is in use" />
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function TablesIndex({ tables, filters, counts, seats, areas, shapes }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'table',
        destroy: 'tables.destroy',
        restore: 'tables.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        { key: 'name', label: 'Table', className: 'cell-strong' },
        { key: 'area', label: 'Area', render: (r) => r.area?.name },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('tables.restore') })]
        : [
              ...baseColumns,
              { key: 'capacity', label: 'Seats', align: 'right', className: 'mono' },
              { key: 'shape', label: 'Shape', render: (r) => r.shape_label },
              {
                  key: 'plan',
                  label: 'Floor plan',
                  render: (r) => (r.pos_x === null ? <span className="text-warn">Not placed</span> : <span className="cell-muted">Placed</span>),
              },
              { key: 'status', label: 'Now', render: (r) => <TableStatusTag table={r} /> },
              {
                  key: 'active',
                  label: 'Status',
                  render: (r) => <StatusDot status={r.is_active ? 'active' : 'inactive'}>{r.is_active ? 'In use' : 'Not in use'}</StatusDot>,
              },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Tables"
                primary={
                    can('tables.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Table
                        </Button>
                    )
                }
            >
                {can('areas.index') && (
                    <Button variant="secondary" icon={MapPin} href={route('areas.index')}>
                        Areas
                    </Button>
                )}
                {can('tables.floor') && (
                    <Button variant="secondary" icon={LayoutGrid} href={route('tables.floor')}>
                        Floor Plan
                    </Button>
                )}
            </PageToolbar>
            <PageStatus>
                <span>
                    {counts.active} tables · {seats} seats
                </span>
            </PageStatus>

            <TrashTabs value={filters.tab} onChange={(tab) => setQuery('tab', tab)} counts={counts} canRestore={can('tables.restore')} />

            <FilterBar count={`${tables.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search tables…" />
                <FilterSelect label="Area" value={query.area} onChange={(v) => setQuery('area', v)} options={[{ value: '', label: 'All' }, ...areas]} />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={tables.data}
                meta={tables.meta}
                noun="tables"
                empty={inTrash ? 'Trash is empty' : 'No tables yet'}
                onRowClick={!inTrash && can('tables.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <TableDrawer
                    key={editing.id ?? 'new'}
                    table={editing.id ? editing : null}
                    areas={areas}
                    shapes={shapes}
                    defaultArea={query.area}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
