import { useEffect, useId, useMemo } from 'react';
import { Image } from 'lucide-react';

/**
 * Image picker in the pos-react settings style (`.stg-upload-box`) with a preview.
 * `value` = File chosen now (or null), `current` = URL already saved. `disabled` = view only.
 */
export default function PhotoUpload({
    value,
    current,
    onChange,
    onRemove,
    removed,
    disabled,
    label = 'Click to upload photo (JPG, PNG, WebP · max 2 MB)',
}) {
    const id = useId();
    const preview = useMemo(() => (value ? URL.createObjectURL(value) : null), [value]);

    useEffect(() => () => preview && URL.revokeObjectURL(preview), [preview]);

    const shown = preview ?? (removed ? null : current);

    return (
        <div className={disabled ? 'photo-upload is-disabled' : 'photo-upload'}>
            {shown && <img className="photo-upload-preview" src={shown} alt="" />}
            <label htmlFor={id} className="stg-upload-box">
                <Image size={18} strokeWidth={1.5} />
                <span>{value ? value.name : label}</span>
                <input
                    id={id}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="sr-only"
                    disabled={disabled}
                    onChange={(e) => onChange(e.target.files?.[0] ?? null)}
                />
            </label>
            {shown && onRemove && !disabled && (
                <button type="button" className="btn btn-ghost btn-xs" onClick={onRemove}>
                    Remove
                </button>
            )}
        </div>
    );
}
