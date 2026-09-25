import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
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
    Select,
    StatusDot,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';

function CounterDrawer({ counter, printers, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(counter?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: counter?.name ?? '',
        receipt_printer: counter?.receipt_printer?.id ?? '',
        is_active: counter?.is_active ?? true,
    });

    // keep an inactive printer that is still linked selectable
    const current = counter?.receipt_printer;
    const options =
        current && !printers.some((p) => p.value === current.id)
            ? [...printers, { value: current.id, label: `${current.name} (inactive)` }]
            : printers;

    function submit() {
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('counters.update', counter.id), opts);
        else post(route('counters.store'), opts);
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Cash Counter — ${counter.name}` : 'Add Cash Counter'}
            footer={
                <>
                    {isEdit && can('counters.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Counter'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Name" required full error={errors.name} hint="e.g. Counter 1, Takeaway Counter">
                    <Input
                        value={data.name}
                        invalid={errors.name}
                        onChange={(e) => setData('name', e.target.value)}
                        autoFocus={!isEdit}
                    />
                </Field>
                <Field
                    label="Receipt printer"
                    error={errors.receipt_printer}
                    hint={
                        options.length ? (
                            'Bills from this counter print here'
                        ) : can('printers.index') ? (
                            <>
                                No receipt printers yet — <Link href={route('printers.index')}>add one</Link>
                            </>
                        ) : (
                            'No receipt printers yet'
                        )
                    }
                >
                    <Select
                        value={data.receipt_printer}
                        invalid={errors.receipt_printer}
                        placeholder="None (browser print)"
                        options={options}
                        onChange={(e) => setData('receipt_printer', e.target.value)}
                    />
                </Field>
                <Field label="Status" error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Active' : 'Inactive'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Counter is active" />
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function CountersIndex({ counters, filters, counts, printers }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'cash counter',
        destroy: 'counters.destroy',
        restore: 'counters.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [{ key: 'name', label: 'Counter', className: 'cell-strong' }];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('counters.restore') })]
        : [
              ...baseColumns,
              {
                  key: 'receipt_printer',
                  label: 'Receipt printer',
                  render: (r) =>
                      r.receipt_printer ? (
                          <>
                              {r.receipt_printer.name}
                              {!r.receipt_printer.is_active && <span className="cell-sub">inactive</span>}
                          </>
                      ) : (
                          <span className="cell-muted">Browser print</span>
                      ),
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
                title="Cash Counters"
                primary={
                    can('counters.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Counter
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>{counts.active} cash counters · one open shift per counter</span>
            </PageStatus>

            <TrashTabs
                value={filters.tab}
                onChange={(tab) => setQuery('tab', tab)}
                counts={counts}
                canRestore={can('counters.restore')}
            />

            <FilterBar count={`${counters.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search counters…" />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={counters.data}
                meta={counters.meta}
                noun="counters"
                empty={inTrash ? 'Trash is empty' : 'No cash counters yet'}
                onRowClick={!inTrash && can('counters.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <CounterDrawer
                    key={editing.id ?? 'new'}
                    counter={editing}
                    printers={printers}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
