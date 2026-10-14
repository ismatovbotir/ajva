<?php

namespace App\Livewire\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
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
            ? User::where('email', $email)->first()
            : User::where('role', UserRole::Admin)->orderBy('id')->first();

        // Empty local database: create the seeder's default admin on the spot.
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

        if (! Auth::attempt($credentials, $this->remember)) {
            $this->addError('email', __('These credentials do not match our records.'));

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
