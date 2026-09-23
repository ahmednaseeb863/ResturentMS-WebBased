import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
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
    Tag,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';

function DesignationDrawer({ designation, types, roles, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(designation?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: designation?.name ?? '',
        type: designation?.type ?? 'other',
        default_role: designation?.default_role?.id ?? '',
        is_active: designation?.is_active ?? true,
    });

    function submit() {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('designations.update', designation.id), options);
        else post(route('designations.store'), options);
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Designation — ${designation.name}` : 'Add Designation'}
            footer={
                <>
                    {isEdit && can('designations.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Designation'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Name" required full error={errors.name} hint="Job title, e.g. Head Waiter">
                    <Input
                        value={data.name}
                        invalid={errors.name}
                        onChange={(e) => setData('name', e.target.value)}
                        autoFocus={!isEdit}
                    />
                </Field>
                <Field label="Works as" required error={errors.type} hint="Used by pickers (waiter, rider lists…)">
                    <Select
                        value={data.type}
                        invalid={errors.type}
                        options={types}
                        onChange={(e) => setData('type', e.target.value)}
                    />
                </Field>
                <Field label="Default role" error={errors.default_role} hint="Pre-selected when a login is created">
                    <Select
                        value={data.default_role}
                        invalid={errors.default_role}
                        placeholder="None"
                        options={roles}
                        onChange={(e) => setData('default_role', e.target.value)}
                    />
                </Field>
                <Field label="Status" error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Active' : 'Inactive'}</span>
                        <Toggle
                            checked={data.is_active}
                            onChange={(v) => setData('is_active', v)}
                            label="Designation is active"
                        />
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function DesignationsIndex({ designations, filters, counts, types, roles }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'designation',
        destroy: 'designations.destroy',
        restore: 'designations.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        { key: 'name', label: 'Designation', className: 'cell-strong' },
        { key: 'type', label: 'Works as', render: (r) => <Tag>{r.type_label.toUpperCase()}</Tag> },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('designations.restore') })]
        : [
              ...baseColumns,
              { key: 'default_role', label: 'Default role', render: (r) => r.default_role?.name ?? '—' },
              { key: 'employees_count', label: 'Staff (this branch)', align: 'center', className: 'mono' },
              {
                  key: 'status',
                  label: 'Status',
                  render: (r) => (
                      <StatusDot status={r.is_active ? 'active' : 'inactive'}>
                          {r.is_active ? 'Active' : 'Inactive'}
                      </StatusDot>
                  ),
              },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Designations"
                primary={
                    can('designations.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Designation
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>{counts.active} designations · shared by all branches</span>
            </PageStatus>

            <TrashTabs
                value={filters.tab}
                onChange={(tab) => setQuery('tab', tab)}
                counts={counts}
                canRestore={can('designations.restore')}
            />

            <FilterBar count={`${designations.meta.total} shown`}>
                <SearchInput
                    value={query.search}
                    onChange={(v) => setQuery('search', v)}
                    placeholder="Search by name…"
                />
                <FilterSelect
                    label="Works as"
                    value={query.type}
                    onChange={(v) => setQuery('type', v)}
                    options={[{ value: '', label: 'All' }, ...types]}
                />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={designations.data}
                meta={designations.meta}
                noun="designations"
                empty={inTrash ? 'Trash is empty' : 'No designations found'}
                onRowClick={!inTrash && can('designations.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <DesignationDrawer
                    key={editing.id ?? 'new'}
                    designation={editing}
                    types={types}
                    roles={roles}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
