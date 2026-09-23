import { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { UtensilsCrossed } from 'lucide-react';
import { cx } from '@/lib/format';

const LAST_USER_KEY = 'rms-last-username';

function lastUsername() {
    try {
        return localStorage.getItem(LAST_USER_KEY) ?? '';
    } catch {
        return '';
    }
}

/**
 * Admin sign-in (pos-react login design). Two modes:
 *  - password: username or email + password (+ remember me)
 *  - pin: username + 4–6 digit PIN, for shared POS / kitchen / waiter devices
 */
export default function Login({ mode: initialMode = 'password' }) {
    const { app, flash } = usePage().props;
    const [mode, setMode] = useState(initialMode);
    const isPin = mode === 'pin';

    const { data, setData, post, processing, errors, clearErrors } = useForm({
        username: isPin ? lastUsername() : '',
        password: '',
        pin: '',
        remember: true,
    });

    function switchMode() {
        clearErrors();
        setData((d) => ({ ...d, password: '', pin: '', username: d.username || lastUsername() }));
        setMode(isPin ? 'password' : 'pin');
    }

    function submit(e) {
        e.preventDefault();
        post(route(isPin ? 'login.pin' : 'login.store'), {
            onSuccess: () => {
                try {
                    localStorage.setItem(LAST_USER_KEY, data.username);
                } catch {
                    /* storage unavailable — nothing to remember */
                }
            },
            onFinish: () => setData((d) => ({ ...d, password: '', pin: '' })),
        });
    }

    return (
        <div className="login-page">
            <Head title="Sign in" />
            <form className="login-window" onSubmit={submit} noValidate>
                <div className="login-body">
                    <div className="login-brand">
                        <UtensilsCrossed size={32} strokeWidth={1.5} />
                        RESTAURANT MS
                    </div>

                    <div className="login-tagline">
                        {isPin ? 'Quick sign in with your PIN' : 'Sign in to your account'}
                    </div>

                    {flash?.error && <div className="login-alert">{flash.error}</div>}

                    <div className="login-form">
                        <div className="login-field">
                            <label htmlFor="username">Username</label>
                            <input
                                id="username"
                                type="text"
                                placeholder={isPin ? 'Enter username' : 'Enter username or email'}
                                autoComplete="username"
                                autoFocus={!isPin || !data.username}
                                value={data.username}
                                onChange={(e) => setData('username', e.target.value)}
                            />
                            {errors.username && <div className="field-error">{errors.username}</div>}
                        </div>

                        {isPin ? (
                            <div className="login-field">
                                <label htmlFor="pin">PIN</label>
                                <input
                                    id="pin"
                                    type="password"
                                    inputMode="numeric"
                                    maxLength={6}
                                    placeholder="4–6 digits"
                                    autoComplete="off"
                                    autoFocus={Boolean(data.username)}
                                    className="login-pin"
                                    value={data.pin}
                                    onChange={(e) => setData('pin', e.target.value.replace(/\D/g, ''))}
                                />
                                {errors.pin && <div className="field-error">{errors.pin}</div>}
                            </div>
                        ) : (
                            <>
                                <div className="login-field">
                                    <label htmlFor="password">Password</label>
                                    <input
                                        id="password"
                                        type="password"
                                        placeholder="Enter password"
                                        autoComplete="current-password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                    />
                                    {errors.password && <div className="field-error">{errors.password}</div>}
                                </div>
                                <button
                                    type="button"
                                    role="checkbox"
                                    aria-checked={data.remember}
                                    className="login-remember"
                                    onClick={() => setData('remember', !data.remember)}
                                >
                                    <span className={cx('login-checkbox', !data.remember && 'off')}>✓</span>
                                    Remember me
                                </button>
                            </>
                        )}
                    </div>

                    <div className="login-actions">
                        <button type="submit" className="login-btn" disabled={processing}>
                            {processing ? 'SIGNING IN…' : 'SIGN IN'}
                        </button>
                        <button type="button" className="login-switch" onClick={switchMode}>
                            {isPin ? 'Use password instead' : 'Sign in with PIN'}
                        </button>
                    </div>
                </div>

                <div className="login-footer">
                    {app?.name} v{app?.version} · © {new Date().getFullYear()}
                </div>
            </form>
        </div>
    );
}
