import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Plus, Printer as PrinterIcon, Trash2 } from 'lucide-react';
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
import { dateTime } from '@/lib/format';

const PAPER = [
    { value: '80', label: '80 mm' },
    { value: '58', label: '58 mm' },
];

/** Opens the test slip; the page prints itself through the browser print dialog. */
function testPrint(printer) {
    window.open(route('printers.test', printer.id), '_blank', 'width=420,height=640');
}

function PrinterDrawer({ printer, types, connections, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(printer?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: printer?.name ?? '',
        type: printer?.type ?? 'receipt',
        connection: printer?.connection ?? 'usb',
        device_name: printer?.device_name ?? '',
        ip_address: printer?.ip_address ?? '',
        port: printer?.port ?? 9100,
        paper_width: String(printer?.paper_width ?? 80),
        is_active: printer?.is_active ?? true,
    });
    const network = data.connection === 'network';

    function submit() {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('printers.update', printer.id), options);
        else post(route('printers.store'), options);
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Printer — ${printer.name}` : 'Add Printer'}
            footer={
                <>
                    {isEdit && can('printers.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    {isEdit && can('printers.test') && (
                        <Button variant="secondary" icon={PrinterIcon} onClick={() => testPrint(printer)}>
                            Test print
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Printer'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Name" required full error={errors.name} hint="e.g. Counter 1 Receipt, Grill KOT">
                    <Input
                        value={data.name}
                        invalid={errors.name}
                        onChange={(e) => setData('name', e.target.value)}
                        autoFocus={!isEdit}
                    />
                </Field>
                <Field label="Prints" required error={errors.type}>
                    <Select
                        value={data.type}
                        invalid={errors.type}
                        options={types}
                        onChange={(e) => setData('type', e.target.value)}
                    />
                </Field>
                <Field label="Paper width" required error={errors.paper_width}>
                    <Select
                        value={data.paper_width}
                        invalid={errors.paper_width}
                        options={PAPER}
                        onChange={(e) => setData('paper_width', e.target.value)}
                    />
                </Field>
                <Field label="Connection" required error={errors.connection}>
                    <Select
                        value={data.connection}
                        invalid={errors.connection}
                        options={connections}
                        onChange={(e) => setData('connection', e.target.value)}
                    />
                </Field>
                <Field label="Status" error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Active' : 'Inactive'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Printer is active" />
                    </div>
                </Field>
                {network ? (
                    <>
                        <Field label="IP address" required error={errors.ip_address} hint="Fixed IP of the printer on the LAN">
                            <Input
                                mono
                                value={data.ip_address}
                                invalid={errors.ip_address}
                                placeholder="192.168.1.50"
                                onChange={(e) => setData('ip_address', e.target.value)}
                            />
                        </Field>
                        <Field label="Port" error={errors.port} hint="Usually 9100">
                            <Input
                                mono
                                type="number"
                                value={data.port ?? ''}
                                invalid={errors.port}
                                onChange={(e) => setData('port', e.target.value)}
                            />
                        </Field>
                    </>
                ) : (
                    <Field
                        label="Printer name on the PC"
                        required
                        full
                        error={errors.device_name}
                        hint="Exactly as shown in the counter PC's printer list (used by QZ Tray)"
                    >
                        <Input
                            mono
                            value={data.device_name}
                            invalid={errors.device_name}
                            placeholder="EPSON TM-T20III Receipt"
                            onChange={(e) => setData('device_name', e.target.value)}
                        />
                    </Field>
                )}
            </FormGrid>
        </Drawer>
    );
}

export default function PrintersIndex({ printers, filters, counts, types, connections, printMethod }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'printer',
        destroy: 'printers.destroy',
        restore: 'printers.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        { key: 'name', label: 'Printer', className: 'cell-strong' },
        {
            key: 'type',
            label: 'Prints',
            render: (r) => <Tag tone={r.type === 'kitchen' ? 'warn' : 'accent'}>{r.type_label.toUpperCase()}</Tag>,
        },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('printers.restore') })]
        : [
              ...baseColumns,
              {
                  key: 'address',
                  label: 'Connection',
                  render: (r) => (
                      <>
                          <span className="mono">{r.address || '—'}</span>
                          <span className="cell-sub">{r.connection_label}</span>
                      </>
                  ),
              },
              { key: 'paper_width', label: 'Paper', className: 'mono', render: (r) => `${r.paper_width} mm` },
              {
                  key: 'counters',
                  label: 'Used by',
                  render: (r) =>
                      r.counters.length ? r.counters.map((c) => c.name).join(', ') : <span className="cell-muted">—</span>,
              },
              {
                  key: 'last_tested_at',
                  label: 'Last test',
                  className: 'cell-muted',
                  render: (r) => (r.last_tested_at ? dateTime(r.last_tested_at) : 'Never'),
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
              ...(can('printers.test')
                  ? [
                        {
                            key: 'test',
                            label: '',
                            align: 'right',
                            render: (r) => (
                                <Button
                                    variant="ghost"
                                    icon={PrinterIcon}
                                    className="btn-xs"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        testPrint(r);
                                    }}
                                >
                                    Test
                                </Button>
                            ),
                        },
                    ]
                  : []),
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Printers"
                primary={
                    can('printers.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Printer
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {counts.active} printers · print method: {printMethod === 'qz' ? 'QZ Tray' : 'browser print'}
                </span>
            </PageStatus>

            <TrashTabs
                value={filters.tab}
                onChange={(tab) => setQuery('tab', tab)}
                counts={counts}
                canRestore={can('printers.restore')}
            />

            <FilterBar count={`${printers.meta.total} shown`}>
                <SearchInput
                    value={query.search}
                    onChange={(v) => setQuery('search', v)}
                    placeholder="Search name, PC printer name or IP…"
                />
                <FilterSelect
                    label="Prints"
                    value={query.type}
                    onChange={(v) => setQuery('type', v)}
                    options={[{ value: '', label: 'All' }, ...types]}
                />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={printers.data}
                meta={printers.meta}
                noun="printers"
                empty={inTrash ? 'Trash is empty' : 'No printers yet'}
                onRowClick={!inTrash && can('printers.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <PrinterDrawer
                    key={editing.id ?? 'new'}
                    printer={editing}
                    types={types}
                    connections={connections}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
