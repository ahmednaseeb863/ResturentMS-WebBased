import { useMemo, useState } from 'react';
import { Plus, Download, Printer, Trash2, RotateCcw } from 'lucide-react';
import {
    Avatar,
    Button,
    ChartBox,
    ConfirmDialog,
    DataTable,
    Drawer,
    EmptyState,
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
    StatCard,
    StatGrid,
    StatusDot,
    Tabs,
    Tag,
    Textarea,
    Toggle,
} from '@/components/ui';
import { money } from '@/lib/format';

// Local-only design gallery (/dev/ui) — compare side by side with pos-react.
const NAMES = [
    'Zara Ahmed',
    'Bilal Farooq',
    'Sana Tariq',
    'Kamran Baig',
    'Hira Malik',
    'Usman Ghani',
    'Nadia Shah',
    'Faisal Qureshi',
];
const ROWS = Array.from({ length: 23 }, (_, i) => ({
    id: `0199a1b2-c3d4-7e5f-8a9b-${String(i).padStart(12, '0')}`,
    name: NAMES[i % NAMES.length],
    phone: `+92-300-${String(1000000 + i * 7919).slice(0, 7)}`,
    orders: (i * 7) % 40,
    spent: (i * 3517) % 90000,
    active: i % 5 !== 4,
}));
const PAGE = 10;

export default function UiKit() {
    const [tab, setTab] = useState('active');
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('All');
    const [page, setPage] = useState(1);
    const [drawer, setDrawer] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [toggle, setToggle] = useState(true);

    const filtered = useMemo(
        () =>
            ROWS.filter((r) => (tab === 'trash' ? !r.active : r.active))
                .filter((r) => !search || r.name.toLowerCase().includes(search.toLowerCase()))
                .filter((r) => status === 'All' || (status === 'Active') === r.active),
        [tab, search, status],
    );
    const lastPage = Math.max(1, Math.ceil(filtered.length / PAGE));
    const rows = filtered.slice((page - 1) * PAGE, page * PAGE);

    const columns = [
        {
            key: 'name',
            label: 'Customer',
            render: (r) => (
                <div className="cust-name-cell">
                    <Avatar name={r.name} />
                    <div className="products-name">{r.name}</div>
                </div>
            ),
        },
        { key: 'phone', label: 'Phone', className: 'mono' },
        { key: 'orders', label: 'Orders', align: 'right', className: 'mono' },
        { key: 'spent', label: 'Spent', align: 'right', className: 'mono', render: (r) => money(r.spent) },
        {
            key: 'status',
            label: 'Status',
            render: (r) => (
                <StatusDot status={r.active ? 'active' : 'inactive'}>{r.active ? 'Active' : 'Inactive'}</StatusDot>
            ),
        },
        {
            key: 'actions',
            label: '',
            align: 'right',
            render: () =>
                tab === 'trash' ? (
                    <Button variant="ghost" icon={RotateCcw}>
                        Restore
                    </Button>
                ) : (
                    <Button
                        variant="ghost"
                        icon={Trash2}
                        onClick={(e) => {
                            e.stopPropagation();
                            setConfirm(true);
                        }}
                    >
                        Trash
                    </Button>
                ),
        },
    ];

    return (
        <PageBody>
            <PageToolbar
                title="UI Kit"
                primary={
                    <Button variant="primary" icon={Plus} onClick={() => setDrawer(true)}>
                        Add Customer
                    </Button>
                }
            >
                <Button icon={Download}>Export</Button>
                <Button icon={Printer}>Print</Button>
            </PageToolbar>
            <PageStatus>
                <span>{ROWS.length} sample rows</span>
            </PageStatus>

            <StatGrid>
                <StatCard label="Today's Sales" value={money(184250)} sub="↑ 12% vs yesterday" />
                <StatCard label="Orders" value="147" sub="92 dine-in · 55 other" />
                <StatCard label="Open Tables" value="9 / 24" sub="3 waiting for bill" tone="neutral" />
                <StatCard label="Cash Short" value={money(-350)} sub="Counter B · Night" tone="danger" />
            </StatGrid>

            <div className="uikit-row">
                <Button variant="primary" icon={Plus}>
                    Primary
                </Button>
                <Button variant="secondary">Secondary</Button>
                <Button variant="ghost">Ghost</Button>
                <Button variant="danger" icon={Trash2}>
                    Danger
                </Button>
                <Button variant="primary" disabled>
                    Disabled
                </Button>
                <Tag tone="accent">Dine-in</Tag>
                <Tag tone="neutral">Takeaway</Tag>
                <Tag tone="info">Delivery</Tag>
                <Tag tone="warn">Pending</Tag>
                <StatusDot status="active">Active</StatusDot>
                <StatusDot status="inactive">Inactive</StatusDot>
                <Toggle checked={toggle} onChange={setToggle} label="Sample toggle" />
            </div>

            <Tabs
                tabs={[
                    { key: 'active', label: 'Active', count: ROWS.filter((r) => r.active).length },
                    { key: 'trash', label: 'Trash', count: ROWS.filter((r) => !r.active).length },
                ]}
                value={tab}
                onChange={(t) => {
                    setTab(t);
                    setPage(1);
                }}
            />

            <FilterBar count={`${filtered.length} customers`}>
                <SearchInput
                    value={search}
                    onChange={(v) => {
                        setSearch(v);
                        setPage(1);
                    }}
                    placeholder="Search by name…"
                />
                <FilterSelect
                    label="Status"
                    value={status}
                    onChange={setStatus}
                    options={['All', 'Active', 'Inactive']}
                />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={rows}
                noun="customers"
                total={filtered.length}
                page={page}
                lastPage={lastPage}
                onPageChange={setPage}
                onRowClick={() => setDrawer(true)}
                stack
            />

            <div className="uikit-grid">
                <ChartBox title="Empty state">
                    <EmptyState
                        title="No deals yet"
                        action={
                            <Button variant="primary" icon={Plus}>
                                Add Deal
                            </Button>
                        }
                    >
                        Deals you create for this branch will appear here.
                    </EmptyState>
                </ChartBox>
                <ChartBox title="Form controls">
                    <FormGrid>
                        <Field label="Name" required>
                            <Input placeholder="Full name" />
                        </Field>
                        <Field label="Phone" error="The phone has already been taken.">
                            <Input mono invalid placeholder="+92-300-0000000" />
                        </Field>
                    </FormGrid>
                </ChartBox>
            </div>

            <Drawer
                open={drawer}
                onClose={() => setDrawer(false)}
                title="Add Customer"
                onSubmit={() => setDrawer(false)}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setDrawer(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit">
                            Add Customer
                        </Button>
                    </>
                }
            >
                <FormGrid>
                    <Field label="Customer Name" required full>
                        <Input placeholder="Full name" autoFocus />
                    </Field>
                    <Field label="Phone">
                        <Input mono placeholder="+92-300-0000000" />
                    </Field>
                    <Field label="Email">
                        <Input type="email" placeholder="email@example.com" />
                    </Field>
                    <Field label="Area" full>
                        <Select placeholder="Select area" options={['Gulberg', 'DHA', 'Johar Town']} />
                    </Field>
                    <Field label="Notes" full hint="Visible to cashiers on the POS.">
                        <Textarea placeholder="Optional notes…" />
                    </Field>
                </FormGrid>
            </Drawer>

            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => setConfirm(false)}
                title="Move to Trash"
                message="This customer will be moved to the Trash. You can restore it later."
                confirmLabel="Move to Trash"
                danger
                reason="optional"
            />
        </PageBody>
    );
}
