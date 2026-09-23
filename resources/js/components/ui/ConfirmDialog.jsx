import { useState } from 'react';
import Dialog from './Dialog';
import Button from './Button';
import { Field, Textarea } from './Form';

/**
 * Confirmation with an optional reason — used for Trash (the reason is saved as
 * `delete_reason`), void, cancel and refund. onConfirm(reason) receives the text.
 */
export default function ConfirmDialog({
    open,
    onClose,
    onConfirm,
    title = 'Are you sure?',
    message,
    confirmLabel = 'Confirm',
    danger = false,
    reason = false, // false | 'optional' | 'required'
    processing = false,
}) {
    const [text, setText] = useState('');
    const blocked = reason === 'required' && text.trim() === '';

    function close() {
        setText('');
        onClose();
    }

    return (
        <Dialog
            open={open}
            onClose={close}
            title={title}
            footer={
                <>
                    <Button variant="ghost" onClick={close}>
                        Cancel
                    </Button>
                    <Button
                        variant={danger ? 'danger' : 'primary'}
                        disabled={blocked || processing}
                        onClick={() => onConfirm(text.trim())}
                    >
                        {processing ? 'Please wait…' : confirmLabel}
                    </Button>
                </>
            }
        >
            {message && <p className="ui-dialog-text">{message}</p>}
            {reason && (
                <Field label="Reason" required={reason === 'required'} full>
                    <Textarea
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        placeholder="Add a short reason…"
                    />
                </Field>
            )}
        </Dialog>
    );
}
