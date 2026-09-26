import { useForm } from '@inertiajs/react';
import { Banknote, Landmark } from 'lucide-react';
import { Button, Dialog, Field, FormGrid, Input, Select } from '@/components/ui';
import { cx, money } from '@/lib/format';

/**
 * Pay a supplier: one purchase (`purchase`) or on account (oldest bills first). Cash comes
 * out of my open shift's drawer; a bank transfer from an account of this branch.
 */
export default function SupplierPayDialog({ supplier, purchase, suggested, bankAccounts, myShift, onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        purchase: purchase?.id ?? null,
        amount: suggested > 0 ? String(suggested) : '',
        method: myShift ? 'cash' : 'bank_transfer',
        bank: bankAccounts[0]?.value ?? '',
        reference: '',
        notes: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('suppliers.pay', supplier.id), { preserveScroll: true, onSuccess: onClose });
    }

    const cash = data.method === 'cash';

    return (
        <Dialog
            open
            onClose={onClose}
            title={purchase ? `Pay ${purchase.code} — ${supplier.name}` : `Pay ${supplier.name}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="supplier-pay-form" disabled={processing}>
                        {processing ? 'Paying…' : `Pay ${data.amount ? money(data.amount) : ''}`}
                    </Button>
                </>
            }
        >
            <form id="supplier-pay-form" onSubmit={submit} noValidate>
                <div className="cash-type-buttons pay-methods pos-disc-types" role="radiogroup" aria-label="Paid from">
                    {[
                        ['cash', myShift ? `Cash · ${myShift.code}` : 'Cash (open your shift)', Banknote],
                        ['bank_transfer', 'Bank Transfer', Landmark],
                    ].map(([value, label, Icon]) => (
                        <button
                            key={value}
                            type="button"
                            role="radio"
                            aria-checked={data.method === value}
                            className={cx('cash-type-btn', data.method === value && 'on')}
                            disabled={value === 'cash' ? !myShift : !bankAccounts.length}
                            onClick={() => setData('method', value)}
                        >
                            <Icon size={14} strokeWidth={1.5} />
                            {label}
                        </button>
                    ))}
                </div>
                <FormGrid>
                    <Field label="Amount" required error={errors.amount || errors.method} hint={purchase ? `${money(purchase.due)} still owed on ${purchase.code}` : 'Goes to the oldest unpaid bills first'}>
                        <Input mono type="number" min="0" step="0.01" inputMode="decimal" value={data.amount} invalid={errors.amount} onChange={(e) => setData('amount', e.target.value)} autoFocus />
                    </Field>
                    {!cash && (
                        <Field label="Bank account" required error={errors.bank}>
                            <Select value={data.bank} invalid={errors.bank} options={bankAccounts} onChange={(e) => setData('bank', e.target.value)} />
                        </Field>
                    )}
                    {!cash && (
                        <Field label="Reference no." error={errors.reference}>
                            <Input mono value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                        </Field>
                    )}
                    <Field label="Note" full error={errors.notes}>
                        <Input value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </Field>
                </FormGrid>
                {errors.purchase && <div className="field-error">{errors.purchase}</div>}
            </form>
        </Dialog>
    );
}
