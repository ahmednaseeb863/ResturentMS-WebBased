import { useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import { Button, Dialog, Field, Input } from '@/components/ui';

/** Manager approval: the server asked for a PIN (`errors.pin`); resend with it. */
export default function PinDialog({ message, error, processing, onSubmit, onClose }) {
    const [pin, setPin] = useState('');

    function submit(e) {
        e.preventDefault();
        if (pin.length >= 4) onSubmit(pin);
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title="Manager Approval"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" icon={ShieldCheck} type="submit" form="pin-form" disabled={pin.length < 4 || processing}>
                        {processing ? 'Checking…' : 'Approve'}
                    </Button>
                </>
            }
        >
            <form id="pin-form" onSubmit={submit} noValidate>
                <p className="ui-dialog-text">{message}</p>
                <Field label="Manager PIN" required error={error}>
                    <Input
                        mono
                        type="password"
                        inputMode="numeric"
                        autoComplete="off"
                        autoFocus
                        maxLength={6}
                        value={pin}
                        invalid={error}
                        onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
                    />
                </Field>
            </form>
        </Dialog>
    );
}
