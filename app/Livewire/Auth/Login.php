<?php

namespace App\Livewire\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.guest', ['title' => 'Log in'])]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    /**
     * Local-only shortcut: with DEV_AUTO_LOGIN=true the login page signs in
     * the first admin (or DEV_AUTO_LOGIN_EMAIL) without a password.
     * Hard-gated to the "local" environment, so it can never fire in production.
     */
    public function mount(): void
    {
        if (! app()->environment('local') || ! config('app.dev_auto_login')) {
            return;
        }

        $email = config('app.dev_auto_login_email');

        $user = $email
            ? User::where('email', $email)->where('is_active', true)->first()
            : User::where('role', UserRole::Admin)->where('is_active', true)->orderBy('id')->first();

        // Empty local database: create the seeder's default admin on the spot (never when the admin exists but is deactivated).
        $deactivated = $email
            ? User::where('email', $email)->exists()
            : User::where('role', UserRole::Admin)->exists();
        if ($user === null && $deactivated) {
            return;
        }

        $user ??= User::create([
            'name' => 'Admin',
            'email' => $email ?: 'admin@example.com',
            'password' => 'password',
            'role' => UserRole::Admin,
        ]);

        Auth::login($user);
        session()->regenerate();

        $home = $user->role?->homeRoute() ?? 'dashboard';

        $this->redirectRoute(Route::has($home) ? $home : 'dashboard', navigate: false);
    }

    public function login(): void
    {
        $credentials = $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials + ['is_active' => true], $this->remember)) {
            // A deactivated account is told so only when the password was right.
            $inactive = User::query()->where('email', $credentials['email'])->where('is_active', false)->first();
            $this->addError('email', $inactive !== null && Hash::check($credentials['password'], $inactive->password)
                ? __('This account is deactivated. Ask an administrator.')
                : __('These credentials do not match our records.'));

            return;
        }

        session()->regenerate();

        // Each role lands on its own home (monitor accounts go straight to the screen).
        $home = Auth::user()->role?->homeRoute() ?? 'dashboard';

        $this->redirectRoute(Route::has($home) ? $home : 'dashboard', navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
