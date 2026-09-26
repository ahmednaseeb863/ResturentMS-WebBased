import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Search, UserPlus } from 'lucide-react';
import { Button, Dialog, Field, FormGrid, Input, Textarea } from '@/components/ui';
import { cx } from '@/lib/format';

/**
 * Customer lookup by phone or name (partial reload of `customers`), quick-add, and for
 * delivery the address: one of the customer's saved addresses or typed in.
 */
export default function CustomerDialog({ value, delivery, onSave, onClose }) {
    const { customers } = usePage().props;
    const [query, setQuery] = useState('');
    const [customer, setCustomer] = useState(value.customer);
    const [address, setAddress] = useState(value.address);
    const [addressText, setAddressText] = useState(value.addressText ?? '');
    const [adding, setAdding] = useState(false);
    const timer = useRef(null);

    useEffect(() => {
        clearTimeout(timer.current);
        if (query.trim().length < 2) return undefined;
        timer.current = setTimeout(() => {
            router.reload({ only: ['customers'], data: { customer_q: query.trim() }, preserveUrl: true });
        }, 300);
        return () => clearTimeout(timer.current);
    }, [query]);

    function pick(c) {
        setCustomer(c);
        const def = c.addresses?.find((a) => a.is_default) ?? c.addresses?.[0];
        setAddress(def?.id ?? null);
        setAdding(false);
    }

    const results = query.trim().length >= 2 ? (customers ?? []) : [];
    const needsAddress = delivery && !address && !addressText.trim();

    return (
        <Dialog
            open
            onClose={onClose}
            title={delivery ? 'Customer & Address' : 'Customer'}
            className="pos-customer-dialog"
            footer={
                <>
                    {customer && (
                        <Button variant="ghost" className="pos-dialog-left" onClick={() => onSave({ customer: null, address: null, addressText: '' })}>
                            No customer
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" disabled={adding} onClick={() => onSave({ customer, address, addressText: address ? '' : addressText })}>
                        Done
                    </Button>
                </>
            }
        >
            {adding ? (
                <QuickAdd phone={/^\+?\d+$/.test(query.trim()) ? query.trim() : ''} delivery={delivery} onAdded={pick} onCancel={() => setAdding(false)} />
            ) : (
                <>
                    <div className="pos-customer-search">
                        <label className="pos-search">
                            <Search size={14} strokeWidth={1.5} />
                            <input
                                className="pos-search-input"
                                type="search"
                                inputMode="search"
                                value={query}
                                placeholder="Phone or name…"
                                autoFocus
                                onChange={(e) => setQuery(e.target.value)}
                            />
                        </label>
                        <Button icon={UserPlus} onClick={() => setAdding(true)}>
                            New
                        </Button>
                    </div>

                    {results.length > 0 && (
                        <div className="pos-customer-results">
                            {results.map((c) => (
                                <button
                                    key={c.id}
                                    type="button"
                                    className={cx('pos-customer-row', customer?.id === c.id && 'on')}
                                    onClick={() => pick(c)}
                                >
                                    <span>{c.name}</span>
                                    <span className="mono cell-muted">{c.phone}</span>
                                </button>
                            ))}
                        </div>
                    )}
                    {query.trim().length >= 2 && results.length === 0 && (
                        <p className="ui-dialog-text">No customer found — add them with “New”.</p>
                    )}

                    {customer && (
                        <div className="pos-customer-picked">
                            <strong>{customer.name}</strong> <span className="mono cell-muted">{customer.phone}</span>
                        </div>
                    )}

                    {delivery && (
                        <div className="pos-opt-group">
                            <div className="pos-opt-title">Delivery address</div>
                            {customer?.addresses?.length > 0 && (
                                <div className="pos-address-list">
                                    {customer.addresses.map((a) => (
                                        <button
                                            key={a.id}
                                            type="button"
                                            className={cx('pos-opt', address === a.id && 'on')}
                                            aria-pressed={address === a.id}
                                            onClick={() => setAddress(a.id)}
                                        >
                                            <span>
                                                {a.label && <strong>{a.label}: </strong>}
                                                {[a.address, a.area].filter(Boolean).join(', ')}
                                            </span>
                                        </button>
                                    ))}
                                    <button type="button" className={cx('pos-opt', !address && 'on')} onClick={() => setAddress(null)}>
                                        <span>Another address…</span>
                                    </button>
                                </div>
                            )}
                            {!address && (
                                <Textarea
                                    value={addressText}
                                    maxLength={500}
                                    placeholder="House, street, area, landmark"
                                    onChange={(e) => setAddressText(e.target.value)}
                                />
                            )}
                            {needsAddress && <p className="field-hint">Needed before the order is sent.</p>}
                        </div>
                    )}
                </>
            )}
        </Dialog>
    );
}

function QuickAdd({ phone: startPhone, delivery, onAdded, onCancel }) {
    const [name, setName] = useState('');
    const [phone, setPhone] = useState(startPhone);
    const [address, setAddress] = useState('');
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    function save() {
        router.post(
            route('pos.customers.store'),
            { name, phone, addresses: address.trim() ? [{ address: address.trim(), is_default: true }] : [] },
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: setErrors,
                onSuccess: (page) => page.props.flash?.customer && onAdded(page.props.flash.customer),
            },
        );
    }

    return (
        <div className="pos-quick-add">
            <FormGrid>
                <Field label="Name" required error={errors.name}>
                    <Input value={name} autoFocus maxLength={120} invalid={errors.name} onChange={(e) => setName(e.target.value)} />
                </Field>
                <Field label="Phone" required error={errors.phone}>
                    <Input mono value={phone} inputMode="tel" maxLength={20} invalid={errors.phone} onChange={(e) => setPhone(e.target.value)} />
                </Field>
                {delivery && (
                    <Field label="Address" full error={errors['addresses.0.address']}>
                        <Textarea value={address} maxLength={255} onChange={(e) => setAddress(e.target.value)} />
                    </Field>
                )}
            </FormGrid>
            <div className="pos-quick-add-actions">
                <Button variant="ghost" onClick={onCancel}>
                    Back to search
                </Button>
                <Button variant="primary" disabled={processing} onClick={save}>
                    {processing ? 'Saving…' : 'Add customer'}
                </Button>
            </div>
        </div>
    );
}
