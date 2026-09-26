import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, Dialog, Field, FormGrid, Input, Textarea } from '@/components/ui';
import { cx, money } from '@/lib/format';
import CashCount, { countPayload, countTotal, emptyCount } from './CashCount';

/** Drawer lines (`.shift-close-summary`) with the expected cash at the bottom. */
export function DrawerSummary({ lines, expected }) {
    return (
        <div className="shift-close-summary">
            {lines.map((l) => (
                <div key={l.key} className="shift-close-row">
                    <span>
                        {l.label}
                        {l.count > 1 && <span className="cell-muted"> ({l.count})</span>}
                    </span>
                    <span className={cx('mono', l.key !== 'opening' && l.amount > 0 && 'cash-plus', l.amount < 0 && 'cash-minus')}>
                        {l.key === 'opening' || l.amount === 0
                            ? money(l.amount)
                            : `${l.amount < 0 ? '−' : '+'}${money(Math.abs(l.amount))}`}
                    </span>
                </div>
            ))}
            <div className="shift-close-row shift-close-expected">
                <span>Expected Cash</span>
                <span className="mono">{money(expected)}</span>
            </div>
        </div>
    );
}

/** Over / short / balanced (`.shift-diff-banner`). */
export function DiffBanner({ diff }) {
    const d = Math.round(diff * 100) / 100;
    return (
        <div className={cx('shift-diff-banner', d === 0 ? 'ok' : d > 0 ? 'over' : 'short')}>
            {d === 0 ? '✓ Balanced — no difference' : d > 0 ? `▲ Over by ${money(d)}` : `▼ Short by ${money(Math.abs(d))}`}
        </div>
    );
}

/**
 * pos-react "Close Shift" modal: expected cash (hidden on blind close), the count, the
 * difference, float left for the next shift, and a manager PIN when the difference is too big.
 */
export default function CloseShiftDialog({ shift, lines, denominations, rules, onClose }) {
    const [counting, setCounting] = useState(rules.require_denominations);
    const [count, setCount] = useState(() => emptyCount(denominations));
    const { data, setData, put, processing, errors, transform } = useForm({
        counted_cash: '',
        float_left: '0',
        notes: '',
        pin: '',
    });

    const counted = counting ? countTotal(count) : Number(data.counted_cash || 0);
    const hasCount = counting ? countTotal(count) > 0 : data.counted_cash !== '';
    const expected = Number(shift.expected_cash ?? 0);
    const diff = counted - expected;
    const overLimit = shift.cash_visible && hasCount && Math.abs(diff) > rules.max_difference;
    const askPin = overLimit || Boolean(errors.pin);
    const handedOver = Math.max(counted - Number(data.float_left || 0), 0);

    function submit(e) {
        e.preventDefault();
        transform((d) => ({
            ...d,
            counted_cash: counting ? countTotal(count) : d.counted_cash,
            count: counting ? countPayload(count) : [],
            pin: askPin ? d.pin : '',
        }));
        put(route('shifts.close', shift.id), { preserveScroll: true, onSuccess: onClose });
    }

    const countError = errors.count ?? Object.entries(errors).find(([k]) => k.startsWith('count.'))?.[1];

    return (
        <Dialog
            open
            onClose={onClose}
            title={`Close Shift — ${shift.code}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="close-shift-form" disabled={processing}>
                        {processing ? 'Closing…' : 'Close Shift'}
                    </Button>
                </>
            }
        >
            <form id="close-shift-form" onSubmit={submit} noValidate>
                {shift.cash_visible ? (
                    <DrawerSummary lines={lines} expected={expected} />
                ) : (
                    <p className="ui-dialog-text">Blind close — count the drawer; the expected cash is shown after closing.</p>
                )}

                <FormGrid className="shift-close-form">
                    {counting ? (
                        <div className="cust-field cust-field-full">
                            <label>
                                Count the drawer<span className="cust-required">*</span>
                            </label>
                            <CashCount value={count} onChange={setCount} error={countError} />
                        </div>
                    ) : (
                        <Field label="Actual closing cash" required full error={errors.counted_cash}>
                            <Input
                                mono
                                type="number"
                                min="0"
                                step="0.01"
                                inputMode="decimal"
                                placeholder="Enter counted cash"
                                value={data.counted_cash}
                                invalid={errors.counted_cash}
                                onChange={(e) => setData('counted_cash', e.target.value)}
                                autoFocus
                            />
                        </Field>
                    )}
                    {!rules.require_denominations && (
                        <div className="cust-field cust-field-full">
                            <button type="button" className="text-link" onClick={() => setCounting((c) => !c)}>
                                {counting ? 'Type the total instead' : 'Count notes & coins instead'}
                            </button>
                        </div>
                    )}

                    {shift.cash_visible && hasCount && (
                        <div className="cust-field cust-field-full">
                            <DiffBanner diff={diff} />
                        </div>
                    )}

                    <Field label="Float left in drawer" error={errors.float_left} hint="Opening cash of the next shift">
                        <Input
                            mono
                            type="number"
                            min="0"
                            step="0.01"
                            inputMode="decimal"
                            value={data.float_left}
                            invalid={errors.float_left}
                            onChange={(e) => setData('float_left', e.target.value)}
                        />
                    </Field>
                    <Field label="Handed over" hint="To the manager / safe">
                        <Input mono value={money(handedOver)} readOnly tabIndex={-1} />
                    </Field>

                    {askPin && (
                        <Field
                            label="Manager PIN"
                            required
                            full
                            error={errors.pin}
                            hint={`The difference is more than the ${money(rules.max_difference)} allowed — recount, or a manager approves with their PIN`}
                        >
                            <Input
                                mono
                                type="password"
                                inputMode="numeric"
                                autoComplete="off"
                                maxLength={6}
                                value={data.pin}
                                invalid={errors.pin}
                                onChange={(e) => setData('pin', e.target.value.replace(/\D/g, ''))}
                            />
                        </Field>
                    )}

                    <Field label="Notes" full error={errors.notes}>
                        <Textarea value={data.notes} placeholder="Closing notes…" onChange={(e) => setData('notes', e.target.value)} />
                    </Field>
                </FormGrid>
            </form>
        </Dialog>
    );
}
