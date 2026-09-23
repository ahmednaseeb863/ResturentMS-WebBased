import { Head, useForm, usePage } from '@inertiajs/react';
import { UtensilsCrossed } from 'lucide-react';
import { cx } from '@/lib/format';

export default function Login() {
    const { app } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({
        username: '',
        password: '',
        remember: true,
    });

    function submit(e) {
        e.preventDefault();
        post(route('login.store'), { onFinish: () => setData('password', '') });
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

                    <div className="login-tagline">Sign in to your account</div>

                    <div className="login-form">
                        <div className="login-field">
                            <label htmlFor="username">Username</label>
                            <input
                                id="username"
                                type="text"
                                placeholder="Enter username"
                                autoComplete="username"
                                autoFocus
                                value={data.username}
                                onChange={(e) => setData('username', e.target.value)}
                            />
                            {errors.username && <div className="field-error">{errors.username}</div>}
                        </div>
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
                    </div>

                    <div className="login-actions">
                        <button type="submit" className="login-btn" disabled={processing}>
                            {processing ? 'SIGNING IN…' : 'SIGN IN'}
                        </button>
                        <span className="login-forgot">Forgot password? Ask your manager.</span>
                    </div>
                </div>

                <div className="login-footer">
                    {app?.name} v{app?.version} · © {new Date().getFullYear()}
                </div>
            </form>
        </div>
    );
}
