import { useState } from 'react';
import { Button, Dialog, Field, FormGrid, Textarea } from '@/components/ui';
import { cx } from '@/lib/format';

const REASONS = ['Customer not answering', 'Wrong address', 'Customer refused', 'Customer not home'];

/** Why a delivery could not be made (rider panel and deliveries board). */
export default function FailDialog({ delivery, processing, error, onSubmit, onClose }) {
    const [reason, setReason] = useState('');

    return (
        <Dialog
            open
            onClose={onClose}
            title={`Could not deliver ${delivery.order.code}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="danger" disabled={processing || !reason.trim()} onClick={() => onSubmit(reason.trim())}>
                        Mark Failed
                    </Button>
                </>
            }
        >
            <div className="pos-opt-grid">
                {REASONS.map((r) => (
                    <button key={r} type="button" className={cx('pos-opt', reason === r && 'on')} onClick={() => setReason(r)}>
                        <span>{r}</span>
                    </button>
                ))}
            </div>
            <FormGrid>
                <Field label="Reason" required full error={error}>
                    <Textarea value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />
                </Field>
            </FormGrid>
        </Dialog>
    );
}
