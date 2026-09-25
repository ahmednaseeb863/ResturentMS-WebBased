import { useState } from 'react';
import { useForm } from '@inertiajs/react';
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
    StatusDot,
    Tag,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';

function AccountDrawer({ account, branches, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(account?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        bank_name: account?.bank_name ?? '',
        account_title: account?.account_title ?? '',
        account_number: account?.account_number ?? '',
        iban: account?.iban ?? '',
        is_active: account?.is_active ?? true,
        show_on_receipt: account?.show_on_receipt ?? false,
        // only the branches this admin can pick; others are kept by the server
        branches: (account?.branches ?? []).map((b) => b.id).filter((id) => branches.some((o) => o.value === id)),
    });

    function toggleBranch(id, on) {
        setData('branches', on ? [...data.branches, id] : data.branches.filter((b) => b !== id));
    }

    function submit() {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('bank-accounts.update', account.id), options);
        else post(route('bank-accounts.store'), options);
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Bank Account — ${account.bank_name}` : 'Add Bank Account'}
            footer={
                <>
                    {isEdit && can('bank-accounts.destroy') && (
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
                <Field label="Bank" required error={errors.bank_name} hint="e.g. Meezan Bank">
                    <Input
                        value={data.bank_name}
                        invalid={errors.bank_name}
                        onChange={(e) => setData('bank_name', e.target.value)}
                        autoFocus={!isEdit}
                    />
                </Field>
                <Field label="Account title" required error={errors.account_title}>
                    <Input
                        value={data.account_title}
                        invalid={errors.account_title}
                        onChange={(e) => setData('account_title', e.target.value)}
                    />
                </Field>
                <Field label="Account number" required error={errors.account_number}>
                    <Input
                        mono
                        value={data.account_number}
                        invalid={errors.account_number}
                        onChange={(e) => setData('account_number', e.target.value)}
                    />
                </Field>
                <Field label="IBAN" error={errors.iban}>
                    <Input
                        mono
                        value={data.iban}
                        invalid={errors.iban}
                        maxLength={40}
                        onChange={(e) => setData('iban', e.target.value.toUpperCase())}
                    />
                </Field>
                <Field label="Show on receipt" error={errors.show_on_receipt}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.show_on_receipt ? 'Printed on bills' : 'Not printed'}</span>
                        <Toggle
                            checked={data.show_on_receipt}
                            onChange={(v) => setData('show_on_receipt', v)}
                            label="Show on receipt"
                        />
                    </div>
                </Field>
                <Field label="Status" error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Active' : 'Inactive'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Account is active" />
                    </div>
                </Field>
                <Field
                    label="Available at branches"
                    required
                    full
                    error={errors.branches}
                    hint="Bank transfers at these branches can go into this account"
                >
                    <div className="check-list">
                        {branches.map((b) => (
                            <CheckItem
                                key={b.value}
                                checked={data.branches.includes(b.value)}
                                onChange={(on) => toggleBranch(b.value, on)}
                            >
                                {b.label}
                            </CheckItem>
                        ))}
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function BankAccountsIndex({ accounts, filters, counts, branches }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'bank account',
        destroy: 'bank-accounts.destroy',
        restore: 'bank-accounts.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        { key: 'bank_name', label: 'Bank', className: 'cell-strong' },
        { key: 'account_title', label: 'Account title' },
        { key: 'account_number', label: 'Account no.', className: 'mono' },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('bank-accounts.restore') })]
        : [
              ...baseColumns,
              { key: 'iban', label: 'IBAN', className: 'mono cell-muted', render: (r) => r.iban || '—' },
              {
                  key: 'branches',
                  label: 'Branches',
                  render: (r) =>
                      r.branches.length ? (
                          <span className="tag-list">
                              {r.branches.map((b) => (
                                  <Tag key={b.id}>{b.name}</Tag>
                              ))}
                          </span>
                      ) : (
                          '—'
                      ),
              },
              {
                  key: 'show_on_receipt',
                  label: 'On receipt',
                  align: 'center',
                  render: (r) => (r.show_on_receipt ? <Tag tone="accent">YES</Tag> : <span className="cell-muted">—</span>),
              },
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
                title="Bank Accounts"
                primary={
                    can('bank-accounts.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Account
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>{counts.active} bank accounts · shared, available per branch</span>
            </PageStatus>

            <TrashTabs
                value={filters.tab}
                onChange={(tab) => setQuery('tab', tab)}
                counts={counts}
                canRestore={can('bank-accounts.restore')}
            />

            <FilterBar count={`${accounts.meta.total} shown`}>
                <SearchInput
                    value={query.search}
                    onChange={(v) => setQuery('search', v)}
                    placeholder="Search bank, title, account no. or IBAN…"
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
                rows={accounts.data}
                meta={accounts.meta}
                noun="accounts"
                empty={inTrash ? 'Trash is empty' : 'No bank accounts yet'}
                onRowClick={!inTrash && can('bank-accounts.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <AccountDrawer
                    key={editing.id ?? 'new'}
                    account={editing}
                    branches={branches}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask({ ...editing, name: `${editing.bank_name} — ${editing.account_title}` })}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
