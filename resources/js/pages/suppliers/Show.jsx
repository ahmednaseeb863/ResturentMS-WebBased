import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ChevronLeft, FileText, Mail, MapPin, Pencil, Phone, Plus, User, Wallet } from 'lucide-react';
import { Button, ContactBlock, DataTable, PageBody, PageStatus, PageToolbar, StatCard, StatGrid, Tabs, Tag } from '@/components/ui';
import SupplierDrawer from '@/components/inventory/SupplierDrawer';
import SupplierPayDialog from '@/components/inventory/SupplierPayDialog';
import useCan from '@/hooks/useCan';
import { date, dateTime, money } from '@/lib/format';

/** A supplier's account in this branch: purchases, payments, what is owed; pay from here. */
export default function SupplierShow({ supplier, purchases, payments, bankAccounts, myShift }) {
    const can = useCan();
    const [tab, setTab] = useState('purchases');
    const [dialog, setDialog] = useState(null); // { kind: 'edit' | 'pay', purchase }

    const billed = purchases.reduce((n, p) => n + Number(p.total) - Number(p.returned_total), 0);
    const paid = payments.reduce((n, p) => n + Number(p.amount), 0);

    const purchaseColumns = [
        { key: 'code', label: 'Purchase', render: (p) => <span className="cell-strong mono">{p.code}</span> },
        { key: 'invoice', label: 'Invoice', render: (p) => (p.invoice_no ? <span className="mono">{p.invoice_no}</span> : <span className="cell-muted">—</span>) },
        { key: 'date', label: 'Date', className: 'mono', render: (p) => date(p.business_date) },
        { key: 'total', label: 'Total', align: 'right', className: 'mono', render: (p) => money(Number(p.total) - Number(p.returned_total)) },
        { key: 'due', label: 'Due', align: 'right', className: 'mono', render: (p) => (p.due > 0 ? money(p.due) : '—') },
        { key: 'status', label: 'Status', render: (p) => <Tag tone={p.payment_status.tone}>{p.payment_status.label}</Tag> },
        {
            key: 'pay',
            label: '',
            align: 'right',
            render: (p) =>
                can('suppliers.pay') &&
                p.due > 0 && (
                    <Button
                        variant="ghost"
                        className="btn-xs"
                        icon={Wallet}
                        onClick={(e) => {
                            e.stopPropagation();
                            setDialog({ kind: 'pay', purchase: p });
                        }}
                    >
                        Pay
                    </Button>
                ),
        },
    ];

    const paymentColumns = [
        { key: 'when', label: 'Paid', className: 'mono cell-muted', render: (p) => dateTime(p.created_at) },
        {
            key: 'method',
            label: 'From',
            render: (p) => (
                <>
                    <Tag tone={p.method.tone}>{p.method.label}</Tag>
                    <span className="cell-sub">{[p.shift?.code, p.bank?.name, p.reference_no].filter(Boolean).join(' · ')}</span>
                </>
            ),
        },
        { key: 'for', label: 'For', render: (p) => (p.purchase ? <span className="mono">{p.purchase.code}</span> : <span className="cell-muted">On account</span>) },
        { key: 'by', label: 'By', render: (p) => p.paid_by?.name },
        { key: 'amount', label: 'Amount', align: 'right', className: 'mono', render: (p) => money(p.amount) },
    ];

    return (
        <PageBody>
            <PageToolbar
                title={supplier.name}
                headTitle={supplier.name}
                primary={
                    can('suppliers.pay') && (
                        <Button variant="primary" icon={Wallet} onClick={() => setDialog({ kind: 'pay', purchase: null })}>
                            Pay Supplier
                        </Button>
                    )
                }
            >
                <Button variant="ghost" icon={ChevronLeft} href={route('suppliers.index')}>
                    Suppliers
                </Button>
                {can('suppliers.update') && (
                    <Button icon={Pencil} onClick={() => setDialog({ kind: 'edit' })}>
                        Edit
                    </Button>
                )}
                {can('purchases.create') && (
                    <Button icon={Plus} href={route('purchases.create', { supplier: supplier.id })}>
                        Receive Purchase
                    </Button>
                )}
            </PageToolbar>
            <PageStatus>
                <span>{supplier.is_active ? 'Active supplier' : 'Inactive supplier'} · shared by all branches · amounts are this branch’s</span>
            </PageStatus>

            <StatGrid>
                <StatCard
                    label="Owed"
                    value={money(Math.max(0, supplier.balance))}
                    sub={supplier.balance < 0 ? `${money(-supplier.balance)} paid in advance` : 'bills − returns − payments'}
                    tone={supplier.balance > 0 ? 'danger' : 'neutral'}
                />
                <StatCard label="Purchases" value={money(billed)} sub={`${purchases.length} bills (latest 100)`} tone="neutral" />
                <StatCard label="Paid" value={money(paid)} sub={`${payments.length} payments`} tone="neutral" />
            </StatGrid>

            <ContactBlock
                rows={[
                    { icon: User, text: supplier.contact_person },
                    { icon: Phone, text: supplier.phone, mono: true },
                    { icon: Mail, text: supplier.email },
                    { icon: MapPin, text: supplier.address },
                    { icon: FileText, text: supplier.ntn && `NTN ${supplier.ntn}`, mono: true },
                ]}
            />
            {supplier.notes && <p className="cell-muted">{supplier.notes}</p>}

            <Tabs
                value={tab}
                onChange={setTab}
                tabs={[
                    { key: 'purchases', label: 'Purchases', count: purchases.length },
                    { key: 'payments', label: 'Payments', count: payments.length },
                ]}
            />
            {tab === 'purchases' ? (
                <DataTable
                    columns={purchaseColumns}
                    rows={purchases}
                    noun="purchases"
                    empty="No purchases from this supplier yet"
                    onRowClick={can('purchases.show') ? (p) => router.visit(route('purchases.show', p.id)) : undefined}
                    stack
                />
            ) : (
                <DataTable columns={paymentColumns} rows={payments} noun="payments" empty="Nothing paid yet" stack />
            )}

            {dialog?.kind === 'edit' && <SupplierDrawer supplier={supplier} onClose={() => setDialog(null)} />}
            {dialog?.kind === 'pay' && (
                <SupplierPayDialog
                    supplier={supplier}
                    purchase={dialog.purchase}
                    suggested={dialog.purchase ? dialog.purchase.due : Math.max(0, supplier.balance)}
                    bankAccounts={bankAccounts}
                    myShift={myShift}
                    onClose={() => setDialog(null)}
                />
            )}
        </PageBody>
    );
}
