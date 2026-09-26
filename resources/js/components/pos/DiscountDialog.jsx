import { useState } from 'react';
import { Button, Dialog, Field, FormGrid, Input } from '@/components/ui';
import { cx, money } from '@/lib/format';
import { discountOn } from '@/lib/pricing';

/**
 * Discount on the order or one line: a predefined discount of that scope, or typed in
 * (percent / amount + reason). `base` is the amount it applies to, for the preview.
 * onSave(spec | null) — spec = { discount, type, value, reason, name, max, min }.
 */
export default function DiscountDialog({ title, scope, presets, value, base, processing, error, onSave, onClose }) {
    const options = presets.filter((d) => d.applies_to === scope);
    const [preset, setPreset] = useState(value?.discount ?? null);
    const [type, setType] = useState(value && !value.discount ? value.type : 'percent');
    const [amount, setAmount] = useState(value && !value.discount ? String(value.value) : '');
    const [reason, setReason] = useState(value?.reason ?? '');

    const chosen = options.find((d) => d.id === preset);
    const spec = chosen
        ? { discount: chosen.id, type: chosen.type, value: chosen.value, reason: reason.trim() || null, name: chosen.name, max: chosen.max_amount, min: chosen.min_amount }
        : Number(amount) > 0
          ? { discount: null, type, value: Number(amount), reason: reason.trim(), name: 'Manual discount', max: null, min: null }
          : null;
    const off = spec ? discountOn(spec, base) : 0;
    const blocked = !spec || (!chosen && !reason.trim()) || (type === 'percent' && !chosen && Number(amount) > 100);

    return (
        <Dialog
            open
            onClose={onClose}
            title={title}
            footer={
                <>
                    {value && (
                        <Button variant="ghost" className="pos-dialog-left" disabled={processing} onClick={() => onSave(null)}>
                            Remove discount
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" disabled={blocked || processing} onClick={() => onSave(spec)}>
                        Apply{off > 0 ? ` · −${money(off)}` : ''}
                    </Button>
                </>
            }
        >
            {options.length > 0 && (
                <div className="pos-opt-group">
                    <div className="pos-opt-title">Predefined</div>
                    <div className="pos-opt-grid">
                        {options.map((d) => (
                            <button
                                key={d.id}
                                type="button"
                                className={cx('pos-opt', preset === d.id && 'on')}
                                aria-pressed={preset === d.id}
                                onClick={() => setPreset(preset === d.id ? null : d.id)}
                            >
                                <span>{d.name}</span>
                                <span className="pos-opt-price mono">
                                    {d.type === 'percent' ? `${d.value}%` : money(d.value)}
                                    {d.requires_approval ? ' · PIN' : ''}
                                </span>
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {!chosen && (
                <div className="pos-opt-group">
                    <div className="pos-opt-title">Typed in</div>
                    <div className="cash-type-buttons pos-disc-types">
                        {[
                            ['percent', 'Percent %'],
                            ['fixed', 'Amount'],
                        ].map(([t, label]) => (
                            <button key={t} type="button" className={cx('cash-type-btn', type === t && 'on')} onClick={() => setType(t)}>
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <FormGrid>
                {!chosen && (
                    <Field label={type === 'percent' ? 'Percent' : 'Amount'} required>
                        <Input mono type="number" min="0" step="0.01" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
                    </Field>
                )}
                <Field label="Reason" required={!chosen} full={Boolean(chosen)}>
                    <Input value={reason} maxLength={255} placeholder="e.g. regular customer" onChange={(e) => setReason(e.target.value)} />
                </Field>
            </FormGrid>

            <p className="field-hint">
                On {money(base)}
                {spec && ` → ${money(off)} off`}
                {spec?.min && base < spec.min ? ` (needs at least ${money(spec.min)})` : ''}
            </p>
            {error && <div className="field-error">{error}</div>}
        </Dialog>
    );
}
