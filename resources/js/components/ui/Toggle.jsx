/** On/off switch (pos-react settings `.stg-toggle`). */
export default function Toggle({ checked, onChange, disabled, label }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            className={`stg-toggle ${checked ? 'on' : 'off'}`}
            onClick={() => onChange(!checked)}
        >
            <span className="stg-toggle-thumb" />
        </button>
    );
}
