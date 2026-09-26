import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button, CheckItem, Dialog, Field, FormGrid, Input, Textarea } from '@/components/ui';
import { QtyStepper } from '@/components/pos/ItemDialog';

/**
 * Void all or part of a sent line (reason; "wasted" keeps ready items out of stock).
 * Manager PIN when the Security & Approvals settings ask for it.
 */
export default function VoidDialog({ order, line, pinRequired, onClose }) {
    const [quantity, setQuantity] = useState(line.quantity);
    const [reason, setReason] = useState('');
    const [wasted, setWasted] = useState(false);
    const [pin, setPin] = useState('');
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    function submit(e) {
        e.preventDefault();
        router.put(
            route('orders.items.void', [order.id, line.id]),
            { quantity, reason, wasted, pin: pin || null },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: setErrors,
                onSuccess: onClose,
            },
        );
    }

    const wasteHint = line.type === 'ready_item' || line.picks.length > 0 ? 'Opened / served — not put back in stock' : 'Already made — the kitchen used the ingredients';

    return (
        <Dialog
            open
            onClose={onClose}
            title={`Void — ${line.full_name}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="danger" type="submit" form="void-form" disabled={!reason.trim() || processing}>
                        {processing ? 'Voiding…' : `Void ${quantity}`}
                    </Button>
                </>
            }
        >
            <form id="void-form" onSubmit={submit} noValidate>
                <FormGrid>
                    {line.quantity > 1 && (
                        <Field label="Quantity to void" error={errors.quantity}>
                            <QtyStepper value={quantity} onChange={(q) => setQuantity(Math.min(q, line.quantity))} />
                        </Field>
                    )}
                    <Field label="Reason" required full error={errors.reason ?? (line.quantity > 1 ? null : errors.quantity)}>
                        <Textarea value={reason} maxLength={255} autoFocus placeholder="e.g. customer changed mind" onChange={(e) => setReason(e.target.value)} />
                    </Field>
                    <div className="cust-field cust-field-full">
                        <CheckItem checked={wasted} onChange={setWasted}>
                            Wasted <span className="cell-muted">— {wasteHint}</span>
                        </CheckItem>
                    </div>
                    {(pinRequired || errors.pin) && (
                        <Field label="Manager PIN" required full error={errors.pin}>
                            <Input
                                mono
                                type="password"
                                inputMode="numeric"
                                autoComplete="off"
                                maxLength={6}
                                value={pin}
                                invalid={errors.pin}
                                onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
                            />
                        </Field>
                    )}
                </FormGrid>
            </form>
        </Dialog>
    );
}
