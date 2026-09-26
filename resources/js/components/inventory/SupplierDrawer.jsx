import { useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { Button, Drawer, Field, FormGrid, Input, Textarea, Toggle } from '@/components/ui';
import useCan from '@/hooks/useCan';

/** Add / edit a supplier (shared by every branch). */
export default function SupplierDrawer({ supplier, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(supplier?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: supplier?.name ?? '',
        contact_person: supplier?.contact_person ?? '',
        phone: supplier?.phone ?? '',
        email: supplier?.email ?? '',
        address: supplier?.address ?? '',
        ntn: supplier?.ntn ?? '',
        notes: supplier?.notes ?? '',
        is_active: supplier?.is_active ?? true,
    });

    function submit() {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('suppliers.update', supplier.id), options);
        else post(route('suppliers.store'), options);
    }

    const field = (key, label, props = {}) => (
        <Field label={label} error={errors[key]} {...props.field}>
            <Input value={data[key]} invalid={errors[key]} onChange={(e) => setData(key, e.target.value)} {...props.input} />
        </Field>
    );

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Supplier — ${supplier.name}` : 'Add Supplier'}
            footer={
                <>
                    {isEdit && onTrash && can('suppliers.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Supplier'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                {field('name', 'Name', { field: { required: true, full: true }, input: { autoFocus: !isEdit } })}
                {field('contact_person', 'Contact person')}
                {field('phone', 'Phone', { input: { mono: true, inputMode: 'tel' } })}
                {field('email', 'Email', { input: { type: 'email' } })}
                {field('ntn', 'NTN / Tax no.', { input: { mono: true } })}
                {field('address', 'Address', { field: { full: true } })}
                <Field label="Notes" full error={errors.notes}>
                    <Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Payment terms, delivery days…" />
                </Field>
                <Field label="Status" error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Active' : 'Inactive'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Supplier is active" />
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}
