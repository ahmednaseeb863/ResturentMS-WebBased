import { useState } from 'react';
import { Button, Dialog, Field, FormGrid, Select } from '@/components/ui';
import { cx, money } from '@/lib/format';

/**
 * Delivery details at the POS: the zone (fee and minimum, when the branch uses zones) and
 * the rider. A rider can be given later on the Deliveries board; it can't change once the
 * food has left.
 */
export default function DeliveryDialog({ zones, riders, value, canAssign, riderLocked, onSave, onClose }) {
    const [zone, setZone] = useState(value.zone);
    const [rider, setRider] = useState(value.rider ?? '');

    return (
        <Dialog
            open
            onClose={onClose}
            title="Delivery"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" onClick={() => onSave({ zone, rider: rider || null })}>
                        Done
                    </Button>
                </>
            }
        >
            {zones.length > 0 && (
                <div className="pos-opt-group">
                    <div className="pos-opt-title">Zone</div>
                    <div className="pos-address-list">
                        {zones.map((z) => (
                            <button
                                key={z.id}
                                type="button"
                                className={cx('pos-opt', zone === z.id && 'on')}
                                aria-pressed={zone === z.id}
                                onClick={() => setZone(z.id)}
                            >
                                <span>{z.name}</span>
                                <span className="pos-opt-price mono">
                                    {money(z.fee)}
                                    {z.minimum > 0 && ` · min ${money(z.minimum)}`}
                                </span>
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {canAssign && (
                <FormGrid>
                    <Field
                        label="Rider"
                        full
                        hint={riderLocked ? 'The rider has the food — change it on the Deliveries board after a return.' : 'Or give it to a rider later on the Deliveries board'}
                    >
                        <Select
                            value={rider}
                            placeholder="No rider yet"
                            disabled={riderLocked}
                            options={riders.map((r) => ({ value: r.value, label: r.active ? `${r.label} · ${r.active} out` : r.label }))}
                            onChange={(e) => setRider(e.target.value)}
                        />
                    </Field>
                </FormGrid>
            )}
            {riders.length === 0 && canAssign && <p className="ui-dialog-text">No active riders — add employees with the Rider designation.</p>}
        </Dialog>
    );
}
