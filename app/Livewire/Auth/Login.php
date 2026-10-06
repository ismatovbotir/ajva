<?php

namespace App\Livewire\Auth;

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
