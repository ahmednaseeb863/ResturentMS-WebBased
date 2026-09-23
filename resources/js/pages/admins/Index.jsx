import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import {
    Button,
    CheckItem,
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
import { dateTime } from '@/lib/format';

function AdminDrawer({ admin, roles, branches, onClose, onTrash }) {
    const can = useCan();
    const { auth } = usePage().props;
    const isEdit = Boolean(admin?.id);
    const isSelf = isEdit && admin.id === auth.user.id;

    const { data, setData, post, put, processing, errors } = useForm({
        name: admin?.name ?? '',
        username: admin?.username ?? '',
        email: admin?.email ?? '',
        password: '',
        password_confirmation: '',
        pin: '',
        clear_pin: false,
        role: admin?.role?.id ?? '',
        branches: admin?.branches?.map((b) => b.id) ?? (branches.length === 1 ? [branches[0].id] : []),
        is_super_admin: admin?.is_super_admin ?? false,
        is_active: admin?.is_active ?? true,
    });

    function toggleBranch(id, on) {
        setData('branches', on ? [...data.branches, id] : data.branches.filter((b) => b !== id));
    }

    function submit() {
        const options = {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setData((d) => ({ ...d, password: '', password_confirmation: '', pin: '' })),
        };
        if (isEdit) put(route('admins.update', admin.id), options);
        else post(route('admins.store'), options);
    }

    return (
        <Drawer
            open
            wide
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Account — ${admin.name}` : 'Add Admin Account'}
            footer={
                <>
                    {isEdit && !isSelf && can('admins.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Account'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Full name" required error={errors.name}>
                    <Input
                        value={data.name}
                        invalid={errors.name}
                        onChange={(e) => setData('name', e.target.value)}
                        autoFocus={!isEdit}
                    />
                </Field>
                <Field label="Username" required error={errors.username}>
                    <Input
                        mono
                        value={data.username}
                        invalid={errors.username}
                        autoComplete="off"
                        onChange={(e) => setData('username', e.target.value.toLowerCase())}
                    />
                </Field>
                <Field label="Email" error={errors.email} hint="Optional — can also be used to sign in">
                    <Input
                        type="email"
                        value={data.email}
                        invalid={errors.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                </Field>
                <Field
                    label="PIN"
                    error={errors.pin}
                    hint={
                        admin?.has_pin
                            ? 'PIN is set — leave empty to keep it'
                            : '4–6 digits for quick sign-in on shared devices'
                    }
                >
                    <Input
                        mono
                        type="password"
                        inputMode="numeric"
                        maxLength={6}
                        autoComplete="new-password"
                        value={data.pin}
                        invalid={errors.pin}
                        disabled={data.clear_pin}
                        onChange={(e) => setData('pin', e.target.value.replace(/\D/g, ''))}
                    />
                </Field>
                <Field
                    label="Password"
                    required={!isEdit}
                    error={errors.password}
                    hint={isEdit ? 'Leave empty to keep the current password' : 'At least 8 characters'}
                >
                    <Input
                        type="password"
                        autoComplete="new-password"
                        value={data.password}
                        invalid={errors.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                </Field>
                <Field label="Confirm password" required={!isEdit}>
                    <Input
                        type="password"
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                </Field>

                {auth.user.is_super_admin && (
                    <Field label="Super admin" full error={errors.is_super_admin}>
                        <div className="field-inline">
                            <div>
                                <div className="field-inline-label">Full access to every branch and module</div>
                                <div className="field-inline-sub">No role or branch list needed</div>
                            </div>
                            <Toggle
                                checked={data.is_super_admin}
                                onChange={(v) => setData('is_super_admin', v)}
                                label="Super admin"
                                disabled={isSelf}
                            />
                        </div>
                    </Field>
                )}

                {!data.is_super_admin && (
                    <>
                        <Field label="Role" required error={errors.role}>
                            <Select
                                value={data.role}
                                invalid={errors.role}
                                placeholder="Select role…"
                                options={roles.map((r) => ({ value: r.id, label: r.name }))}
                                onChange={(e) => setData('role', e.target.value)}
                            />
                        </Field>
                        <Field label="Status" error={errors.is_active}>
                            <div className="field-inline">
                                <span className="field-inline-label">{data.is_active ? 'Active' : 'Inactive'}</span>
                                <Toggle
                                    checked={data.is_active}
                                    onChange={(v) => setData('is_active', v)}
                                    label="Account is active"
                                    disabled={isSelf}
                                />
                            </div>
                        </Field>
                        <Field label="Branch access" required full error={errors.branches}>
                            <div className="check-list">
                                {branches.map((b) => (
                                    <CheckItem
                                        key={b.id}
                                        checked={data.branches.includes(b.id)}
                                        onChange={(on) => toggleBranch(b.id, on)}
                                    >
                                        {b.name} <span className="mono cell-muted">{b.code}</span>
                                    </CheckItem>
                                ))}
                            </div>
                        </Field>
                    </>
                )}

                {data.is_super_admin && (
                    <Field label="Status" error={errors.is_active}>
                        <div className="field-inline">
                            <span className="field-inline-label">{data.is_active ? 'Active' : 'Inactive'}</span>
                            <Toggle
                                checked={data.is_active}
                                onChange={(v) => setData('is_active', v)}
                                label="Account is active"
                                disabled={isSelf}
                            />
                        </div>
                    </Field>
                )}

                {admin?.has_pin && (
                    <Field full>
                        <CheckItem checked={data.clear_pin} onChange={(v) => setData('clear_pin', v)}>
                            Remove the PIN (PIN sign-in will stop working for this account)
                        </CheckItem>
                    </Field>
                )}
            </FormGrid>
        </Drawer>
    );
}

export default function AdminsIndex({ admins, filters, counts, roles, branches }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'admin account',
        destroy: 'admins.destroy',
        restore: 'admins.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        {
            key: 'name',
            label: 'Name',
            render: (r) => (
                <div className="users-name-cell">
                    <div className="users-avatar">{r.initials}</div>
                    <span className="cell-strong">{r.name}</span>
                </div>
            ),
        },
        { key: 'username', label: 'Username', className: 'mono' },
        {
            key: 'role',
            label: 'Role',
            render: (r) =>
                r.is_super_admin ? (
                    <Tag tone="accent" className="users-role-tag">
                        SUPER ADMIN
                    </Tag>
                ) : (
                    <Tag className="users-role-tag">{(r.role?.name ?? '—').toUpperCase()}</Tag>
                ),
        },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('admins.restore') })]
        : [
              ...baseColumns,
              {
                  key: 'branches',
                  label: 'Branches',
                  render: (r) =>
                      r.is_super_admin ? (
                          <span className="cell-muted">All branches</span>
                      ) : (
                          <div className="tag-list">
                              {r.branches.map((b) => (
                                  <Tag key={b.id}>{b.code}</Tag>
                              ))}
                          </div>
                      ),
              },
              { key: 'pin', label: 'PIN', align: 'center', render: (r) => (r.has_pin ? '✓' : '—') },
              { key: 'last_login_at', label: 'Last login', render: (r) => dateTime(r.last_login_at) },
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
                title="Admin Accounts"
                primary={
                    can('admins.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Account
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {counts.active} accounts · {roles.length} roles
                </span>
            </PageStatus>

            <TrashTabs
                value={filters.tab}
                onChange={(tab) => setQuery('tab', tab)}
                counts={counts}
                canRestore={can('admins.restore')}
            />

            <FilterBar count={`${admins.meta.total} shown`}>
                <SearchInput
                    value={query.search}
                    onChange={(v) => setQuery('search', v)}
                    placeholder="Search by name, username or email…"
                />
                <FilterSelect
                    label="Role"
                    value={query.role}
                    onChange={(v) => setQuery('role', v)}
                    options={[
                        { value: '', label: 'All' },
                        { value: 'super', label: 'Super Admin' },
                        ...roles.map((r) => ({ value: r.id, label: r.name })),
                    ]}
                />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={admins.data}
                meta={admins.meta}
                noun="accounts"
                empty={inTrash ? 'Trash is empty' : 'No admin accounts found'}
                onRowClick={!inTrash && can('admins.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <AdminDrawer
                    key={editing.id ?? 'new'}
                    admin={editing}
                    roles={roles}
                    branches={branches}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
