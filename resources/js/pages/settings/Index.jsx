import { useEffect, useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import {
    ChefHat,
    ClipboardList,
    Clock,
    ConciergeBell,
    Globe,
    Lock,
    Package,
    Percent,
    Printer,
    Receipt,
    Save,
    ShieldCheck,
    Store,
    Truck,
    Wallet,
} from 'lucide-react';
import { Button, PageStatus, PageToolbar, PhotoUpload, Toggle } from '@/components/ui';
import useCan from '@/hooks/useCan';
import { cx, money } from '@/lib/format';

const ICONS = {
    general: Store,
    orders: ClipboardList,
    tax: Percent,
    service_charge: ConciergeBell,
    delivery: Truck,
    payments: Wallet,
    receipt: Receipt,
    printing: Printer,
    kitchen: ChefHat,
    inventory: Package,
    shifts: Clock,
    approvals: ShieldCheck,
};

/** Human text of a value (used for "Global: …" / "Default: …" hints). */
function display(field, value) {
    if (value === null || value === undefined || value === '') return 'Not set';
    switch (field.type) {
        case 'bool':
            return value ? 'On' : 'Off';
        case 'select':
            return field.options.find((o) => o.value === String(value))?.label ?? value;
        case 'money':
            return money(value);
        case 'image':
            return 'Image';
        default:
            return field.suffix ? `${value} ${field.suffix}` : String(value);
    }
}

/** The input for one field (pos-react `.stg-*` controls). */
function Control({ field, value, onChange, disabled, invalid }) {
    const cls = cx('stg-input', invalid && 'is-invalid');

    switch (field.type) {
        case 'bool':
            return <Toggle checked={Boolean(value)} onChange={onChange} disabled={disabled} label={field.label} />;
        case 'select':
            return (
                <select className={cls} value={value ?? ''} disabled={disabled} onChange={(e) => onChange(e.target.value)}>
                    {field.options.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </select>
            );
        case 'text':
            return (
                <textarea
                    className={cx(cls, 'stg-textarea')}
                    rows={3}
                    value={value ?? ''}
                    disabled={disabled}
                    maxLength={field.max ?? undefined}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
        case 'int':
        case 'decimal':
        case 'money':
            return (
                <div className="stg-number">
                    {field.type === 'money' && <span className="stg-unit">{money(0).split(' ')[0]}</span>}
                    <input
                        className={cx(cls, 'stg-input-sm mono')}
                        type="number"
                        inputMode="decimal"
                        step={field.type === 'int' ? 1 : 0.01}
                        min={field.min ?? undefined}
                        max={field.max ?? undefined}
                        value={value ?? ''}
                        disabled={disabled}
                        onChange={(e) => onChange(e.target.value)}
                    />
                    {field.suffix && <span className="stg-unit">{field.suffix}</span>}
                </div>
            );
        case 'time':
            return (
                <input
                    className={cx(cls, 'stg-input-sm mono')}
                    type="time"
                    value={value ?? ''}
                    disabled={disabled}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
        default:
            return (
                <input
                    className={cls}
                    value={value ?? ''}
                    disabled={disabled}
                    maxLength={field.max ?? undefined}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
    }
}

/** "Use global / Override" switch of a branch field. */
function ScopeSwitch({ overridden, onChange, disabled }) {
    return (
        <div className="stg-scope" role="group" aria-label="Value source">
            <button
                type="button"
                className={cx(!overridden && 'active')}
                disabled={disabled}
                onClick={() => onChange(false)}
            >
                Use global
            </button>
            <button
                type="button"
                className={cx(overridden && 'active')}
                disabled={disabled}
                onClick={() => onChange(true)}
            >
                Override
            </button>
        </div>
    );
}

function SettingRow({ field, scope, form, readOnly }) {
    const { data, setData, errors } = form;
    const isBranch = scope === 'branch';
    const overridden = isBranch ? data.overrides.includes(field.key) : true;
    const error = errors[`values.${field.key}`] ?? errors[`files.${field.key}`];
    const full = field.type === 'text' || field.type === 'image';

    function setOverride(on) {
        setData((d) => ({
            ...d,
            overrides: on ? [...d.overrides, field.key] : d.overrides.filter((k) => k !== field.key),
            // switching back shows the global value again
            values: on ? d.values : { ...d.values, [field.key]: field.inherited },
        }));
    }

    const setValue = (v) => setData((d) => ({ ...d, values: { ...d.values, [field.key]: v } }));

    let hint = null;
    if (isBranch && !overridden) hint = 'Uses the global value';
    else if (isBranch) hint = `Global: ${display(field, field.inherited)}`;
    else if (field.overridden) hint = `Default: ${display(field, field.inherited)}`;

    const control =
        field.type === 'image' ? (
            <PhotoUpload
                current={isBranch && !overridden ? field.inherited : field.value}
                value={data.files[field.key] ?? null}
                removed={Boolean(data.remove[field.key])}
                disabled={readOnly || !overridden}
                label="Click to upload (PNG, JPG, WebP · max 1 MB)"
                onChange={(file) =>
                    setData((d) => ({
                        ...d,
                        files: { ...d.files, [field.key]: file },
                        remove: { ...d.remove, [field.key]: false },
                    }))
                }
                onRemove={() =>
                    setData((d) => ({
                        ...d,
                        files: { ...d.files, [field.key]: null },
                        remove: { ...d.remove, [field.key]: true },
                    }))
                }
            />
        ) : (
            <Control
                field={field}
                value={data.values[field.key]}
                onChange={setValue}
                disabled={readOnly || !overridden}
                invalid={Boolean(error)}
            />
        );

    return (
        <div className={cx('stg-row', full && 'stg-row-full', isBranch && !overridden && 'stg-row-inherited')}>
            <div className="stg-row-label">
                <div className="stg-label">
                    {field.label}
                    {field.required && <span className="cust-required">*</span>}
                </div>
                {field.sub && <div className="stg-sub">{field.sub}</div>}
                {(hint || field.override_count > 0) && (
                    <div className="stg-origin">
                        {hint}
                        {field.override_count > 0 && (
                            <span className="stg-origin-count">
                                {hint && ' · '}
                                {field.override_count} branch{field.override_count > 1 ? 'es' : ''} override
                            </span>
                        )}
                    </div>
                )}
                {error && <div className="field-error">{error}</div>}
            </div>
            <div className={cx('stg-row-control', full && 'stg-row-control-full')}>
                {isBranch && <ScopeSwitch overridden={overridden} onChange={setOverride} disabled={readOnly} />}
                {control}
            </div>
        </div>
    );
}

function initialForm(group, scope) {
    const fields = group.sections.flatMap((s) => s.fields);
    return {
        values: Object.fromEntries(fields.filter((f) => f.type !== 'image').map((f) => [f.key, f.value])),
        overrides: scope === 'branch' ? fields.filter((f) => f.overridden).map((f) => f.key) : [],
        files: {},
        remove: {},
    };
}

/**
 * One group's form (and the toolbar Save button, so it knows when the form is dirty).
 * Remounted with fresh values after every save or group switch.
 */
function GroupForm({ group, scope, saveRoute, readOnly, dirtyRef, onSaved }) {
    const form = useForm(initialForm(group, scope));

    useEffect(() => {
        dirtyRef.current = form.isDirty;
    }, [dirtyRef, form.isDirty]);

    function submit(e) {
        e.preventDefault();
        if (readOnly) return;
        form.transform((d) => ({ ...d, _method: 'put' }));
        form.post(saveRoute, { preserveScroll: true, onSuccess: onSaved });
    }

    return (
        <form id="settings-form" onSubmit={submit} className="stg-form">
            <PageToolbar
                title="Settings"
                primary={
                    !readOnly && (
                        <Button
                            variant="primary"
                            icon={Save}
                            type="submit"
                            form="settings-form"
                            disabled={!form.isDirty || form.processing}
                        >
                            {form.processing ? 'Saving…' : 'Save Changes'}
                        </Button>
                    )
                }
            />
            {readOnly && (
                <div className="stg-readonly">
                    <Lock size={14} strokeWidth={1.5} />
                    You can view these settings but not change them.
                </div>
            )}
            {group.sections.map((section) => (
                <div key={section.title} className="stg-section">
                    <div className="stg-section-title">{section.title}</div>
                    {section.fields.map((field) => (
                        <SettingRow key={field.key} field={field} scope={scope} form={form} readOnly={readOnly} />
                    ))}
                </div>
            ))}
        </form>
    );
}

export default function SettingsIndex({ scope, branch, groups, group: initialGroup }) {
    const can = useCan();
    const [active, setActive] = useState(initialGroup);
    const [version, setVersion] = useState(0);
    const dirtyRef = useRef(false);
    const group = groups.find((g) => g.key === active) ?? groups[0];

    const isBranch = scope === 'branch';
    const saveRoute = isBranch ? route(`settings.branch.${group.key}`) : route('settings.global.update', group.key);
    const canEdit = isBranch ? can(`settings.branch.${group.key}`) : can('settings.global.update');

    function pick(key) {
        if (key === active) return;
        if (dirtyRef.current && !window.confirm('Discard unsaved changes in this group?')) return;
        dirtyRef.current = false;
        setActive(key);
    }

    const Icon = ICONS[group.key] ?? Store;

    return (
        <>
            <PageStatus>
                <span>{isBranch ? `Branch settings · ${branch.name}` : 'Global settings · default for every branch'}</span>
            </PageStatus>

            <div className="stg-page">
                <nav className="stg-nav" aria-label="Setting groups">
                    {can('settings.index') && can('settings.global') && (
                        <div className="stg-scope stg-scope-nav" role="group" aria-label="Level">
                            <Link href={route('settings.index', { group: group.key })} className={cx(isBranch && 'active')}>
                                <Store size={13} strokeWidth={1.5} /> This branch
                            </Link>
                            <Link
                                href={route('settings.global', { group: group.key })}
                                className={cx(!isBranch && 'active')}
                            >
                                <Globe size={13} strokeWidth={1.5} /> Global
                            </Link>
                        </div>
                    )}
                    {groups.map((g) => {
                        const GIcon = ICONS[g.key] ?? Store;
                        return (
                            <button
                                key={g.key}
                                type="button"
                                className={cx('stg-nav-item', g.key === group.key && 'active')}
                                onClick={() => pick(g.key)}
                            >
                                <span className="stg-nav-icon">
                                    <GIcon size={14} strokeWidth={1.5} />
                                </span>
                                <span className="stg-nav-label">{g.label}</span>
                            </button>
                        );
                    })}
                </nav>

                <div className="stg-panel">
                    <div className="stg-panel-header">
                        <div className="stg-panel-title">
                            <Icon size={15} strokeWidth={1.5} />
                            {group.label}
                            <span className="stg-panel-scope">{isBranch ? branch.name : 'Global'}</span>
                        </div>
                        <div className="stg-sub">
                            {group.description}
                            {isBranch && ' · fields on “Use global” follow the global settings'}
                        </div>
                    </div>
                    <div className="stg-panel-body">
                        <GroupForm
                            key={`${scope}-${group.key}-${version}`}
                            group={group}
                            scope={scope}
                            saveRoute={saveRoute}
                            readOnly={!canEdit}
                            dirtyRef={dirtyRef}
                            onSaved={() => setVersion((v) => v + 1)}
                        />
                    </div>
                </div>
            </div>
        </>
    );
}
