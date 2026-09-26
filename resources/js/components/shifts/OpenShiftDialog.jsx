import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Button, Dialog, Field, FormGrid, Input, Select, Textarea } from '@/components/ui';
import useCan from '@/hooks/useCan';
import { date, money } from '@/lib/format';
import CashCount, { countPayload, countTotal, emptyCount } from './CashCount';
import StaffPicker from './StaffPicker';

/** pos-react "Open New Shift" modal: counter, shift type, opening cash (typed or counted), staff, notes. */
export default function OpenShiftDialog({ counters, shiftTypes, suggestedType, staffOptions, denominations, businessDate, onClose }) {
    const can = useCan();
    const free = counters.filter((c) => !c.busy);
    const first = free[0];
    const [counting, setCounting] = useState(false);
    const [count, setCount] = useState(() => emptyCount(denominations));
    const { data, setData, post, processing, errors, transform } = useForm({
        counter: first?.value ?? '',
        shift_type: suggestedType ?? '',
        opening_cash: first ? String(first.float) : '',
        notes: '',
        staff: [],
    });

    function pickCounter(value) {
        const counter = counters.find((c) => c.value === value);
        setData((d) => ({ ...d, counter: value, opening_cash: counter ? String(counter.float) : d.opening_cash }));
    }

    function submit(e) {
        e.preventDefault();
        transform((d) =>
            counting ? { ...d, opening_cash: countTotal(count), count: countPayload(count) } : { ...d, count: [] },
        );
        post(route('shifts.open'), { preserveScroll: true });
    }

    const countError = Object.entries(errors).find(([k]) => k.startsWith('count'))?.[1];
    const floatLeft = counters.find((c) => c.value === data.counter)?.float ?? 0;

    return (
        <Dialog
            open
            onClose={onClose}
            title="Open New Shift"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="open-shift-form" disabled={processing || !free.length}>
                        {processing ? 'Opening…' : 'Open Shift'}
                    </Button>
                </>
            }
        >
            {!free.length ? (
                <p className="ui-dialog-text">
                    Every cash counter already has an open shift.{' '}
                    {can('counters.index') && <Link href={route('counters.index')}>Add a counter</Link>}
                </p>
            ) : (
                <form id="open-shift-form" onSubmit={submit} noValidate>
                    <FormGrid>
                        <Field label="Cash counter" required error={errors.counter}>
                            <Select
                                value={data.counter}
                                invalid={errors.counter}
                                options={free}
                                onChange={(e) => pickCounter(e.target.value)}
                            />
                        </Field>
                        <Field label="Shift type" error={errors.shift_type}>
                            <Select
                                value={data.shift_type}
                                invalid={errors.shift_type}
                                placeholder="None"
                                options={shiftTypes}
                                onChange={(e) => setData('shift_type', e.target.value)}
                            />
                        </Field>
                        <Field
                            label="Opening cash"
                            required
                            full
                            error={errors.opening_cash}
                            hint={
                                floatLeft > 0
                                    ? `${money(floatLeft)} was left as float by the last shift on this counter`
                                    : 'Cash in the drawer at the start'
                            }
                        >
                            <div className="shift-cash-input">
                                <Input
                                    mono
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputMode="decimal"
                                    value={counting ? countTotal(count) : data.opening_cash}
                                    readOnly={counting}
                                    invalid={errors.opening_cash}
                                    onChange={(e) => setData('opening_cash', e.target.value)}
                                    autoFocus
                                />
                                <Button variant="secondary" onClick={() => setCounting((c) => !c)} aria-pressed={counting}>
                                    {counting ? 'Type amount' : 'Count notes'}
                                </Button>
                            </div>
                        </Field>
                        {counting && (
                            <div className="cust-field cust-field-full">
                                <CashCount value={count} onChange={setCount} error={countError} />
                            </div>
                        )}
                        {staffOptions.length > 0 && (
                            <Field label="Staff on duty" full error={errors.staff} hint="Optional — more can check in later">
                                <StaffPicker options={staffOptions} value={data.staff} onChange={(v) => setData('staff', v)} />
                            </Field>
                        )}
                        <Field label="Notes" full error={errors.notes}>
                            <Textarea
                                value={data.notes}
                                placeholder="Optional notes for this shift…"
                                onChange={(e) => setData('notes', e.target.value)}
                            />
                        </Field>
                    </FormGrid>
                    <p className="shift-open-date">
                        Business day <strong>{date(businessDate)}</strong> — fixed for this shift, even after midnight.
                    </p>
                </form>
            )}
        </Dialog>
    );
}
