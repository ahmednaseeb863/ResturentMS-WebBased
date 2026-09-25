import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import {
    Button,
    DataTable,
    Drawer,
    Field,
    FilterBar,
    FormGrid,
    Input,
    PageBody,
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

function AreaDrawer({ area, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(area?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: area?.name ?? '',
        sort_order: area?.sort_order ?? 0,
        is_active: area?.is_active ?? true,
    });

    function submit() {
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('areas.update', area.id), opts);
        else post(route('areas.store'), opts);
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Area — ${area.name}` : 'Add Dining Area'}
            footer={
                <>
                    {isEdit && can('areas.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Area'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Name" required full error={errors.name} hint="e.g. Hall, Rooftop, Family">
                    <Input value={data.name} invalid={errors.name} onChange={(e) => setData('name', e.target.value)} autoFocus={!isEdit} />
                </Field>
                <Field label="Sort order" error={errors.sort_order} hint="Order of the floor-plan tabs">
                    <Input
                        type="number"
                        min="0"
                        value={data.sort_order}
                        invalid={errors.sort_order}
                        onChange={(e) => setData('sort_order', e.target.value)}
                    />
                </Field>
                <Field label="Status" error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'In use' : 'Closed'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Area is in use" />
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function AreasIndex({ areas, filters, counts }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'area',
        destroy: 'areas.destroy',
        restore: 'areas.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [{ key: 'name', label: 'Area', className: 'cell-strong' }];
    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('areas.restore') })]
        : [
              ...baseColumns,
              { key: 'tables_count', label: 'Tables', align: 'right', className: 'mono' },
              { key: 'seats', label: 'Seats', align: 'right', className: 'mono', render: (r) => r.seats ?? 0 },
              {
                  key: 'status',
                  label: 'Status',
                  render: (r) => <StatusDot status={r.is_active ? 'active' : 'inactive'}>{r.is_active ? 'In use' : 'Closed'}</StatusDot>,
              },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Dining Areas"
                primary={
                    can('areas.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Area
                        </Button>
                    )
                }
            >
                {can('tables.index') && (
                    <Button variant="secondary" icon={ArrowLeft} href={route('tables.index')}>
                        Tables
                    </Button>
                )}
            </PageToolbar>

            <TrashTabs value={filters.tab} onChange={(tab) => setQuery('tab', tab)} counts={counts} canRestore={can('areas.restore')} />

            <FilterBar count={`${areas.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search areas…" />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={areas.data}
                meta={areas.meta}
                noun="areas"
                empty={inTrash ? 'Trash is empty' : 'No dining areas yet'}
                onRowClick={!inTrash && can('areas.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <AreaDrawer key={editing.id ?? 'new'} area={editing.id ? editing : null} onClose={() => setEditing(null)} onTrash={() => trash.ask(editing)} />
            )}
            {trash.dialog}
        </PageBody>
    );
}
