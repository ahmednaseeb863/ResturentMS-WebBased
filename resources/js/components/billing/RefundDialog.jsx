import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button, Dialog, Field, FormGrid, Input, Select, Textarea } from '@/components/ui';
import { cx, dateTime, money } from '@/lib/format';

/**
 * Give money back on a payment (PLAN §4.13): all or part of it, as cash from your shift's
 * drawer or by bank transfer, with a reason and — when the settings say so — a manager PIN.
 */
export default function RefundDialog({ order, bankAccounts, pinRequired, onClose }) {
    const payments = order.payments.filter((p) => Number(p.refundable) > 0);
    const [paymentId, setPaymentId] = useState(payments[0]?.id ?? '');
    const payment = payments.find((p) => p.id === paymentId);
    const [amount, setAmount] = useState(payment ? String(payment.refundable) : '');
    const [method, setMethod] = useState(payment?.method.value ?? 'cash');
    const [bank, setBank] = useState(payment?.bank?.id ?? bankAccounts[0]?.value ?? '');
    const [reference, setReference] = useState('');
    const [reason, setReason] = useState('');
    const [pin, setPin] = useState('');
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    function pick(id) {
        const p = payments.find((x) => x.id === id);
        setPaymentId(id);
        setAmount(p ? String(p.refundable) : '');
        setMethod(p?.method.value ?? 'cash');
        if (p?.bank) setBank(p.bank.id);
    }

    function submit(e) {
        e.preventDefault();
        router.post(
            route('orders.payments.refund', [order.id, paymentId]),
            { amount, method, bank: method === 'bank_transfer' ? bank : null, reference: reference || null, reason, pin: pin || null },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: setErrors,
                onSuccess: onClose,
            },
        );
    }

    const tooMuch = payment && Number(amount) > Number(payment.refundable);
    const blocked = processing || !payment || !(Number(amount) > 0) || tooMuch || !reason.trim() || (pinRequired && pin.length < 4);

    return (
        <Dialog
            open
            onClose={onClose}
            className="pay-dialog"
            title={`Refund — ${order.code}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="danger" type="submit" form="refund-form" disabled={blocked}>
                        {processing ? 'Refunding…' : `Refund ${money(Number(amount) || 0)}`}
                    </Button>
                </>
            }
        >
            <form id="refund-form" onSubmit={submit} noValidate>
                <FormGrid>
                    <Field label="Payment" required full>
                        <Select
                            value={paymentId}
                            onChange={(e) => pick(e.target.value)}
                            options={payments.map((p) => ({
                                value: p.id,
                                label: `${p.method.label}${p.bank ? ` · ${p.bank.bank}` : ''} · ${money(p.amount)} · ${dateTime(p.created_at)}${Number(p.refunded_total) > 0 ? ` (${money(p.refunded_total)} refunded)` : ''}`,
                            }))}
                        />
                    </Field>
                    <Field label="Amount" required error={errors.amount ?? (tooMuch ? `Up to ${money(payment.refundable)}.` : null)} hint={payment ? `Up to ${money(payment.refundable)}` : null}>
                        <Input mono type="number" min="0" step="0.01" inputMode="decimal" value={amount} invalid={tooMuch} onChange={(e) => setAmount(e.target.value)} />
                    </Field>
                    <Field label="Give back as" required>
                        <div className="cash-type-buttons pos-disc-types">
                            {[
                                ['cash', 'Cash'],
                                ['bank_transfer', 'Bank transfer'],
                            ].map(([value, label]) => (
                                <button key={value} type="button" className={cx('cash-type-btn', method === value && 'on')} onClick={() => setMethod(value)}>
                                    {label}
                                </button>
                            ))}
                        </div>
                    </Field>
                    {method === 'bank_transfer' && (
                        <>
                            <Field label="From bank account" required error={errors.bank}>
                                <Select value={bank} onChange={(e) => setBank(e.target.value)} options={bankAccounts} />
                            </Field>
                            <Field label="Reference no.">
                                <Input value={reference} maxLength={80} onChange={(e) => setReference(e.target.value)} />
                            </Field>
                        </>
                    )}
                    <Field label="Reason" required full error={errors.reason}>
                        <Textarea value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />
                    </Field>
                    {pinRequired && (
                        <Field label="Manager PIN" required full error={errors.pin}>
                            <Input mono type="password" inputMode="numeric" autoComplete="off" maxLength={6} value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))} />
                        </Field>
                    )}
                </FormGrid>
                {method === 'cash' && <p className="field-hint">Cash comes out of your open shift’s drawer.</p>}
                {errors.payment && <div className="field-error">{errors.payment}</div>}
            </form>
        </Dialog>
    );
}
