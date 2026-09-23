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
    Textarea,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';

const EMPTY = { code: '', name: '', phone: '', email: '', tax_number: '', address: '', is_active: true };

function BranchDrawer({ branch, managers, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(branch?.id);
    const { data, setData, post, put, processing, errors } = useForm(
        isEdit
            ? {
                  ...Object.fromEntries(Object.keys(EMPTY).map((k) => [k, branch[k] ?? EMPTY[k]])),
                  manager: branch.manager?.id ?? '',
              }
            : EMPTY,
    );
    const managerOptions = (managers[branch?.id] ?? []).map((e) => ({
        value: e.id,
        label: e.designation ? `${e.name} — ${e.designation}` : e.name,
    }));

    function submit() {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('branches.update', branch.id), options);
        else post(route('branches.store'), options);
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Branch — ${branch.name}` : 'Add Branch'}
            footer={
                <>
                    {isEdit && can('branches.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Branch'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Code" required error={errors.code} hint="Short unique code, e.g. GUL">
                    <Input
                        mono
                        value={data.code}
                        invalid={errors.code}
                        maxLength={20}
                        onChange={(e) => setData('code', e.target.value.toUpperCase())}
                        autoFocus={!isEdit}
                    />
                </Field>
                <Field label="Name" required error={errors.name}>
                    <Input value={data.name} invalid={errors.name} onChange={(e) => setData('name', e.target.value)} />
                </Field>
                <Field label="Phone" error={errors.phone}>
                    <Input
                        value={data.phone}
                        invalid={errors.phone}
                        onChange={(e) => setData('phone', e.target.value)}
                    />
                </Field>
                <Field label="Email" error={errors.email}>
                    <Input
                        type="email"
                        value={data.email}
                        invalid={errors.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                </Field>
                <Field label="Tax / NTN number" error={errors.tax_number}>
                    <Input
                        mono
                        value={data.tax_number}
                        invalid={errors.tax_number}
                        onChange={(e) => setData('tax_number', e.target.value)}
                    />
                </Field>
                <Field label="Status" error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Active' : 'Inactive'}</span>
                        <Toggle
                            checked={data.is_active}
                            onChange={(v) => setData('is_active', v)}
                            label="Branch is active"
                        />
                    </div>
                </Field>
                <Field label="Address" full error={errors.address}>
                    <Textarea
                        value={data.address}
                        invalid={errors.address}
                        onChange={(e) => setData('address', e.target.value)}
                    />
                </Field>
                {isEdit && (
                    <Field
                        label="Branch manager"
                        full
                        error={errors.manager}
                        hint={
                            managerOptions.length
                                ? 'Their login gets access to this branch automatically'
                                : 'Add employees to this branch first (Employees screen)'
                        }
                    >
                        <Select
                            value={data.manager}
                            invalid={errors.manager}
                            placeholder="No manager"
                            options={managerOptions}
                            onChange={(e) => setData('manager', e.target.value)}
                        />
                    </Field>
                )}
            </FormGrid>
        </Drawer>
    );
}

export default function BranchesIndex({ branches, filters, counts, managers }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'branch',
        destroy: 'branches.destroy',
        restore: 'branches.restore',
        reason: 'required',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        { key: 'code', label: 'Code', className: 'mono' },
        { key: 'name', label: 'Branch', className: 'cell-strong' },
        { key: 'phone', label: 'Phone', className: 'mono', render: (r) => r.phone || '—' },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('branches.restore') })]
        : [
              ...baseColumns,
              {
                  key: 'manager',
                  label: 'Manager',
                  render: (r) => r.manager?.name ?? <span className="cell-muted">—</span>,
              },
              { key: 'address', label: 'Address', className: 'cell-muted', render: (r) => r.address || '—' },
              { key: 'admins_count', label: 'Staff logins', align: 'center', className: 'mono' },
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
                title="Branches"
                primary={
                    can('branches.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Branch
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {counts.active} branches · {counts.trash} in trash
                </span>
            </PageStatus>

            <TrashTabs
                value={filters.tab}
                onChange={(tab) => setQuery('tab', tab)}
                counts={counts}
                canRestore={can('branches.restore')}
            />

            <FilterBar count={`${branches.meta.total} shown`}>
                <SearchInput
                    value={query.search}
                    onChange={(v) => setQuery('search', v)}
                    placeholder="Search by name, code or phone…"
                />
                {!inTrash && (
                    <FilterSelect
                        label="Status"
                        value={query.status}
                        onChange={(v) => setQuery('status', v)}
                        options={[
                            { value: 'all', label: 'All' },
                            { value: 'active', label: 'Active' },
                            { value: 'inactive', label: 'Inactive' },
                        ]}
                    />
                )}
            </FilterBar>

            <DataTable
                columns={columns}
                rows={branches.data}
                meta={branches.meta}
                noun="branches"
                empty={inTrash ? 'Trash is empty' : 'No branches found'}
                onRowClick={!inTrash && can('branches.update') ? setEditing : undefined}
                stack
            />

            {editing && (
                <BranchDrawer
                    key={editing.id ?? 'new'}
                    branch={editing}
                    managers={managers}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
