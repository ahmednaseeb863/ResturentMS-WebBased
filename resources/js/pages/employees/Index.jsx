import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { KeyRound, Plus, Trash2 } from 'lucide-react';
import {
    Button,
    CheckItem,
    DataTable,
    Drawer,
    Field,
    FilterBar,
    FilterSelect,
    FormGrid,
    FormSection,
    Input,
    PageBody,
    PageStatus,
    PageToolbar,
    PhotoUpload,
    SearchInput,
    Select,
    StatusDot,
    Tag,
    Textarea,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';
import { date, dateTime } from '@/lib/format';

/** Employee status → pos-react status dot colour. */
const STATUS_DOT = { active: 'active', on_leave: 'partial', left: 'cancelled' };

function EmployeeAvatar({ employee }) {
    return (
        <div className="users-avatar">
            {employee.photo_url ? <img src={employee.photo_url} alt="" /> : employee.initials}
        </div>
    );
}

function LoginFields({ data, setLogin, errors, login, roles, branches, homeBranch, employeeActive }) {
    const err = (k) => errors[`login.${k}`];
    const extra = branches.filter((b) => b.id !== homeBranch?.id);

    function toggleBranch(id, on) {
        setLogin('branches', on ? [...data.branches, id] : data.branches.filter((b) => b !== id));
    }

    return (
        <>
            <Field label="Username" required error={err('username')}>
                <Input
                    mono
                    value={data.username}
                    invalid={err('username')}
                    autoComplete="off"
                    onChange={(e) => setLogin('username', e.target.value.toLowerCase())}
                />
            </Field>
            <Field label="Email" error={err('email')} hint="Optional — can also be used to sign in">
                <Input
                    type="email"
                    value={data.email}
                    invalid={err('email')}
                    onChange={(e) => setLogin('email', e.target.value)}
                />
            </Field>
            <Field
                label="Password"
                required={!login}
                error={err('password')}
                hint={login ? 'Leave empty to keep the current password' : 'At least 8 characters'}
            >
                <Input
                    type="password"
                    autoComplete="new-password"
                    value={data.password}
                    invalid={err('password')}
                    onChange={(e) => setLogin('password', e.target.value)}
                />
            </Field>
            <Field label="Confirm password" required={!login}>
                <Input
                    type="password"
                    autoComplete="new-password"
                    value={data.password_confirmation}
                    onChange={(e) => setLogin('password_confirmation', e.target.value)}
                />
            </Field>
            <Field label="Role" required error={err('role')}>
                <Select
                    value={data.role}
                    invalid={err('role')}
                    placeholder="Select role…"
                    options={roles}
                    onChange={(e) => setLogin('role', e.target.value)}
                />
            </Field>
            <Field
                label="PIN"
                error={err('pin')}
                hint={login?.has_pin ? 'PIN is set — leave empty to keep it' : '4–6 digits for quick sign-in'}
            >
                <Input
                    mono
                    type="password"
                    inputMode="numeric"
                    maxLength={6}
                    autoComplete="new-password"
                    value={data.pin}
                    invalid={err('pin')}
                    disabled={data.clear_pin}
                    onChange={(e) => setLogin('pin', e.target.value.replace(/\D/g, ''))}
                />
            </Field>
            <Field label="Can sign in" full error={err('is_active')}>
                <div className="field-inline">
                    <div>
                        <div className="field-inline-label">{data.is_active && employeeActive ? 'Yes' : 'No'}</div>
                        {!employeeActive && (
                            <div className="field-inline-sub">Off while the employee is not active</div>
                        )}
                    </div>
                    <Toggle
                        checked={data.is_active && employeeActive}
                        onChange={(v) => setLogin('is_active', v)}
                        label="Login is active"
                        disabled={!employeeActive}
                    />
                </div>
            </Field>
            <Field label="Branch access" full error={err('branches')}>
                <div className="check-list">
                    {homeBranch && (
                        <CheckItem checked disabled onChange={() => {}}>
                            {homeBranch.name} <span className="mono cell-muted">{homeBranch.code} · home</span>
                        </CheckItem>
                    )}
                    {extra.map((b) => (
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
            {login?.has_pin && (
                <Field full>
                    <CheckItem checked={data.clear_pin} onChange={(v) => setLogin('clear_pin', v)}>
                        Remove the PIN (PIN sign-in will stop working)
                    </CheckItem>
                </Field>
            )}
        </>
    );
}

function LoginSummary({ login }) {
    return (
        <Field full>
            <div className="field-inline">
                <div>
                    <div className="field-inline-label mono">{login.username}</div>
                    <div className="field-inline-sub">
                        {login.role?.name ?? 'No role'} · last sign-in {dateTime(login.last_login_at)}
                    </div>
                </div>
                <StatusDot status={login.is_active ? 'active' : 'inactive'}>
                    {login.is_active ? 'Can sign in' : 'Disabled'}
                </StatusDot>
            </div>
        </Field>
    );
}

function EmployeeDrawer({ employee, props, onClose, onTrash }) {
    const can = useCan();
    const { designations, roles, branches, homeBranch, statuses, nextCode } = props;
    const isEdit = Boolean(employee?.id);
    const login = employee?.login ?? null;
    const canEditLogin = login ? !login.in_trash && can('admins.update') : can('admins.store');

    const { data, setData, post, processing, errors, transform } = useForm({
        code: employee?.code ?? '',
        name: employee?.name ?? '',
        designation: employee?.designation?.id ?? '',
        phone: employee?.phone ?? '',
        cnic: employee?.cnic ?? '',
        address: employee?.address ?? '',
        joining_date: employee?.joining_date ?? '',
        salary: employee?.salary ?? '',
        status: employee?.status ?? 'active',
        photo: null,
        remove_photo: false,
        login: {
            enabled: Boolean(login),
            username: login?.username ?? '',
            email: login?.email ?? '',
            password: '',
            password_confirmation: '',
            pin: '',
            clear_pin: false,
            role: login?.role?.id ?? '',
            branches: login?.branches.map((b) => b.id).filter((id) => id !== homeBranch?.id) ?? [],
            is_active: login?.is_active ?? true,
        },
    });

    const setLogin = (key, value) => setData('login', { ...data.login, [key]: value });

    function pickDesignation(id) {
        const role = designations.find((d) => d.id === id)?.default_role;
        setData((d) => ({
            ...d,
            designation: id,
            // a new login follows the designation's default role
            login: !login && role ? { ...d.login, role } : d.login,
        }));
    }

    function submit() {
        transform((d) => (isEdit ? { ...d, _method: 'put' } : d));
        post(isEdit ? route('employees.update', employee.id) : route('employees.store'), {
            preserveScroll: true,
            forceFormData: Boolean(data.photo),
            onSuccess: onClose,
        });
    }

    const designationOptions = designations
        .filter((d) => d.is_active || d.id === employee?.designation?.id)
        .map((d) => ({ value: d.id, label: d.name }));

    return (
        <Drawer
            open
            wide
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Employee — ${employee.name}` : 'Add Employee'}
            footer={
                <>
                    {isEdit && can('employees.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Employee'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Photo" full error={errors.photo}>
                    <PhotoUpload
                        value={data.photo}
                        current={employee?.photo_url}
                        removed={data.remove_photo}
                        onChange={(file) => setData((d) => ({ ...d, photo: file, remove_photo: false }))}
                        onRemove={() => setData((d) => ({ ...d, photo: null, remove_photo: true }))}
                    />
                </Field>
                <Field label="Full name" required error={errors.name}>
                    <Input
                        value={data.name}
                        invalid={errors.name}
                        onChange={(e) => setData('name', e.target.value)}
                        autoFocus={!isEdit}
                    />
                </Field>
                <Field
                    label="Employee code"
                    error={errors.code}
                    hint={isEdit ? undefined : `Leave empty for ${nextCode}`}
                >
                    <Input
                        mono
                        value={data.code}
                        invalid={errors.code}
                        placeholder={isEdit ? undefined : nextCode}
                        onChange={(e) => setData('code', e.target.value.toUpperCase())}
                    />
                </Field>
                <Field label="Designation" required error={errors.designation}>
                    <Select
                        value={data.designation}
                        invalid={errors.designation}
                        placeholder="Select designation…"
                        options={designationOptions}
                        onChange={(e) => pickDesignation(e.target.value)}
                    />
                </Field>
                <Field label="Status" required error={errors.status}>
                    <Select
                        value={data.status}
                        invalid={errors.status}
                        options={statuses}
                        onChange={(e) => setData('status', e.target.value)}
                    />
                </Field>
                <Field label="Phone" error={errors.phone}>
                    <Input
                        mono
                        inputMode="tel"
                        value={data.phone}
                        invalid={errors.phone}
                        onChange={(e) => setData('phone', e.target.value)}
                    />
                </Field>
                <Field label="CNIC" error={errors.cnic} hint="12345-1234567-1">
                    <Input
                        mono
                        inputMode="numeric"
                        maxLength={15}
                        value={data.cnic}
                        invalid={errors.cnic}
                        onChange={(e) => setData('cnic', e.target.value)}
                    />
                </Field>
                <Field label="Joining date" error={errors.joining_date}>
                    <Input
                        type="date"
                        value={data.joining_date}
                        invalid={errors.joining_date}
                        onChange={(e) => setData('joining_date', e.target.value)}
                    />
                </Field>
                <Field label="Monthly salary" error={errors.salary} hint="For records only">
                    <Input
                        mono
                        type="number"
                        min="0"
                        step="0.01"
                        value={data.salary ?? ''}
                        invalid={errors.salary}
                        onChange={(e) => setData('salary', e.target.value)}
                    />
                </Field>
                <Field label="Address" full error={errors.address}>
                    <Textarea
                        value={data.address}
                        invalid={errors.address}
                        onChange={(e) => setData('address', e.target.value)}
                    />
                </Field>

                <FormSection icon={KeyRound} title="Login account">
                    {homeBranch && `Home branch: ${homeBranch.name}`}
                </FormSection>

                {login?.in_trash && (
                    <Field full>
                        <div className="field-hint">
                            This employee’s login is in the trash — restore it from Admin Accounts.
                        </div>
                    </Field>
                )}

                {!login && !can('admins.store') && (
                    <Field full>
                        <div className="field-hint">No login. You do not have permission to create logins.</div>
                    </Field>
                )}

                {!login && can('admins.store') && (
                    <Field full error={errors['login.enabled']}>
                        <CheckItem checked={data.login.enabled} onChange={(v) => setLogin('enabled', v)}>
                            Create a login so this employee can sign in
                        </CheckItem>
                    </Field>
                )}

                {login && !login.in_trash && !canEditLogin && <LoginSummary login={login} />}

                {canEditLogin && (login || data.login.enabled) && (
                    <LoginFields
                        data={data.login}
                        setLogin={setLogin}
                        errors={errors}
                        login={login}
                        roles={roles}
                        branches={branches}
                        homeBranch={homeBranch}
                        employeeActive={data.status === 'active'}
                    />
                )}
            </FormGrid>
        </Drawer>
    );
}

export default function EmployeesIndex(props) {
    const { employees, filters, counts, designations, statuses, homeBranch } = props;
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'employee',
        destroy: 'employees.destroy',
        restore: 'employees.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        {
            key: 'name',
            label: 'Employee',
            render: (r) => (
                <div className="cust-name-cell">
                    <EmployeeAvatar employee={r} />
                    <div>
                        <div className="products-name">{r.name}</div>
                        <div className="cust-sub-id">{r.code}</div>
                    </div>
                </div>
            ),
        },
        {
            key: 'designation',
            label: 'Designation',
            render: (r) => <Tag className="users-role-tag">{(r.designation?.name ?? '—').toUpperCase()}</Tag>,
        },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('employees.restore') })]
        : [
              ...baseColumns,
              { key: 'phone', label: 'Phone', className: 'mono', render: (r) => r.phone || '—' },
              {
                  key: 'login',
                  label: 'Login',
                  render: (r) =>
                      r.login && !r.login.in_trash ? (
                          <span className="mono">{r.login.username}</span>
                      ) : (
                          <span className="cell-muted">No login</span>
                      ),
              },
              { key: 'joining_date', label: 'Joined', render: (r) => date(r.joining_date) },
              {
                  key: 'status',
                  label: 'Status',
                  render: (r) => <StatusDot status={STATUS_DOT[r.status]}>{r.status_label}</StatusDot>,
              },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Employees"
                primary={
                    can('employees.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Employee
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {counts.active} employees · {homeBranch?.name ?? 'no branch'}
                </span>
            </PageStatus>

            <TrashTabs
                value={filters.tab}
                onChange={(tab) => setQuery('tab', tab)}
                counts={counts}
                canRestore={can('employees.restore')}
            />

            <FilterBar count={`${employees.meta.total} shown`}>
                <SearchInput
                    value={query.search}
                    onChange={(v) => setQuery('search', v)}
                    placeholder="Search by name, code, phone or CNIC…"
                />
                <FilterSelect
                    label="Designation"
                    value={query.designation}
                    onChange={(v) => setQuery('designation', v)}
                    options={[
                        { value: '', label: 'All' },
                        ...designations.map((d) => ({ value: d.id, label: d.name })),
                    ]}
                />
                {!inTrash && (
                    <FilterSelect
                        label="Status"
                        value={query.status}
                        onChange={(v) => setQuery('status', v)}
                        options={[{ value: '', label: 'All' }, ...statuses]}
                    />
                )}
            </FilterBar>

            <DataTable
                columns={columns}
                rows={employees.data}
                meta={employees.meta}
                noun="employees"
                empty={inTrash ? 'Trash is empty' : 'No employees found'}
                onRowClick={!inTrash && can('employees.update') ? setEditing : undefined}
                rowClassName={(r) => (r.status !== 'active' ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <EmployeeDrawer
                    key={editing.id ?? 'new'}
                    employee={editing}
                    props={props}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
