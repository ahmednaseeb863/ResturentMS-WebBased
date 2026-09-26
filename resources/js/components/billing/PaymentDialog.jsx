import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Banknote, Landmark, Split } from 'lucide-react';
import { Button, Dialog, Field, FormGrid, Input, Select } from '@/components/ui';
import { cx, money } from '@/lib/format';

const round2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;

/** Exact, then the next round notes above the amount (e.g. 2,450 → 2,500 · 3,000 · 5,000). */
function quickCash(due) {
    const steps = [100, 500, 1000, 5000];
    const out = [due];
    for (const step of steps) {
        const next = Math.ceil(due / step) * step;
        if (next > due && !out.includes(next)) out.push(next);
    }
    return out.slice(0, 4);
}

/**
 * Take payment (PLAN §4.13): cash (received → change), bank transfer (account, reference,
 * screenshot) or both. `due` is what the order — or the part of a split bill — still owes;
 * less than that is a part payment. The server re-checks everything.
 */
export default function PaymentDialog({ order, split = null, bankAccounts, rules, returnTo = null, onClose }) {
    const due = round2(split ? split.due : order.due);
    const methods = [
        rules.cash && { value: 'cash', label: 'Cash', icon: Banknote },
        rules.bank_transfer && { value: 'bank', label: 'Bank Transfer', icon: Landmark },
        rules.cash && rules.bank_transfer && { value: 'both', label: 'Cash + Transfer', icon: Split },
    ].filter(Boolean);

    const [mode, setMode] = useState(methods[0]?.value ?? 'cash');
    const [received, setReceived] = useState('');
    const [bank, setBank] = useState(bankAccounts[0]?.value ?? '');
    const [bankAmount, setBankAmount] = useState(String(due));
    const [reference, setReference] = useState('');
    const [proof, setProof] = useState(null);
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    const usesCash = mode !== 'bank';
    const usesBank = mode !== 'cash';
    const transfer = usesBank ? Math.min(due, Math.max(0, round2(bankAmount || 0))) : 0;
    const cashShare = round2(due - transfer);
    const cashIn = received === '' ? cashShare : round2(received); // blank = exact cash
    const cashOnBill = usesCash ? Math.min(cashIn, cashShare) : 0;
    const change = usesCash ? Math.max(0, round2(cashIn - cashShare)) : 0;
    const paying = round2(cashOnBill + transfer);
    const left = round2(due - paying);

    const bankMissing = usesBank && transfer > 0 && (!bank || (rules.transfer_reference_required && !reference.trim()) || (rules.transfer_proof_required && !proof));
    const blocked = processing || paying <= 0 || bankMissing;

    function submit(e) {
        e.preventDefault();
        if (blocked) return;

        const tenders = [];
        if (usesCash && cashOnBill > 0) tenders.push({ method: 'cash', amount: cashOnBill, tendered: cashIn });
        if (usesBank && transfer > 0) tenders.push({ method: 'bank_transfer', amount: transfer, bank, reference: reference.trim() || null, proof });

        router.post(
            route('orders.payments.store', order.id),
            { split: split?.id ?? null, tenders, return: returnTo },
            {
                preserveScroll: true,
                forceFormData: Boolean(proof),
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: setErrors,
                onSuccess: onClose,
            },
        );
    }

    const error = errors.payment ?? errors.tenders ?? errors.split ?? Object.values(errors)[0];

    return (
        <Dialog
            open
            onClose={onClose}
            className="pay-dialog"
            title={`Payment — ${order.code}${split ? ` · ${split.label}` : ''}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="pay-form" disabled={blocked}>
                        {processing ? 'Saving…' : left > 0 && paying > 0 ? `Take ${money(paying)} (part)` : `Take ${money(paying)}`}
                    </Button>
                </>
            }
        >
            <form id="pay-form" onSubmit={submit} noValidate>
                <div className="pay-due">
                    <span>{split ? `${split.label} owes` : 'Amount due'}</span>
                    <strong className="mono">{money(due)}</strong>
                </div>

                {methods.length > 1 && (
                    <div className={cx('cash-type-buttons pay-methods', methods.length === 2 && 'pos-disc-types')} role="radiogroup" aria-label="Payment method">
                        {methods.map((m) => (
                            <button
                                key={m.value}
                                type="button"
                                role="radio"
                                aria-checked={mode === m.value}
                                className={cx('cash-type-btn', mode === m.value && 'on')}
                                onClick={() => {
                                    setMode(m.value);
                                    setBankAmount(m.value === 'both' ? '' : String(due));
                                }}
                            >
                                <m.icon size={14} strokeWidth={1.5} />
                                {m.label}
                            </button>
                        ))}
                    </div>
                )}
                {methods.length === 0 && <div className="field-error">No payment method is switched on — see Settings → Payments.</div>}

                {usesBank && (
                    <FormGrid className="pay-section">
                        <Field label="Into bank account" required full error={errors['tenders.0.bank'] ?? errors['tenders.1.bank']}>
                            <Select value={bank} onChange={(e) => setBank(e.target.value)} options={bankAccounts} placeholder={bankAccounts.length ? undefined : 'No bank account at this branch'} />
                        </Field>
                        <Field label={mode === 'both' ? 'Transfer amount' : 'Amount'} required>
                            <Input mono type="number" min="0" step="0.01" inputMode="decimal" value={bankAmount} onChange={(e) => setBankAmount(e.target.value)} />
                        </Field>
                        <Field label="Reference no." required={rules.transfer_reference_required} error={errors['tenders.0.reference'] ?? errors['tenders.1.reference']}>
                            <Input value={reference} maxLength={80} onChange={(e) => setReference(e.target.value)} />
                        </Field>
                        <Field label="Transfer screenshot" required={rules.transfer_proof_required} full error={errors['tenders.0.proof'] ?? errors['tenders.1.proof']}>
                            <input type="file" accept="image/*" className="cust-input" onChange={(e) => setProof(e.target.files[0] ?? null)} />
                        </Field>
                    </FormGrid>
                )}

                {usesCash && (
                    <div className="pay-section">
                        {mode === 'both' && (
                            <div className="shift-close-row pay-share">
                                <span>Cash to pay</span>
                                <span className="mono">{money(cashShare)}</span>
                            </div>
                        )}
                        <Field label="Cash received" error={errors['tenders.0.tendered']}>
                            <Input
                                mono
                                autoFocus
                                type="number"
                                min="0"
                                step="0.01"
                                inputMode="decimal"
                                placeholder={String(cashShare)}
                                value={received}
                                onChange={(e) => setReceived(e.target.value)}
                            />
                        </Field>
                        {cashShare > 0 && (
                            <div className="pay-quick">
                                {quickCash(cashShare).map((amount, i) => (
                                    <button key={amount} type="button" className={cx('pos-opt', Number(received) === amount && 'on')} onClick={() => setReceived(String(amount))}>
                                        {i === 0 ? 'Exact' : money(amount)}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                <div className="shift-close-summary pay-summary">
                    {usesCash && cashOnBill > 0 && (
                        <div className="shift-close-row">
                            <span>Cash</span>
                            <span className="mono">{money(cashOnBill)}</span>
                        </div>
                    )}
                    {usesBank && transfer > 0 && (
                        <div className="shift-close-row">
                            <span>Bank transfer</span>
                            <span className="mono">{money(transfer)}</span>
                        </div>
                    )}
                    {left > 0 && (
                        <div className="shift-close-row">
                            <span>Still due after this</span>
                            <span className="mono cash-minus">{money(left)}</span>
                        </div>
                    )}
                    <div className={cx('shift-close-row shift-close-expected', change > 0 && 'pay-change')}>
                        <span>Change to give back</span>
                        <span className="mono">{money(change)}</span>
                    </div>
                </div>

                {error && <div className="field-error">{error}</div>}
            </form>
        </Dialog>
    );
}
