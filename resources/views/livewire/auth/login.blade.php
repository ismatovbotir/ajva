<div class="w-full max-w-sm">
    <div class="mb-8 text-center">
        <span class="text-2xl font-semibold text-brand-700">{{ config('app.name') }}</span>
    </div>

    <x-ui.card>
        <h1 class="mb-6 text-lg font-semibold text-slate-900">{{ __('Log in to your account') }}</h1>

        <form wire:submit="login" class="space-y-4">
            <x-ui.input
                name="email"
                type="email"
                :label="__('Email')"
                wire:model="email"
                autofocus
                autocomplete="username"
            />

            <x-ui.input
                name="password"
                type="password"
                :label="__('Password')"
                wire:model="password"
                autocomplete="current-password"
            />

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" wire:model="remember" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                {{ __('Remember me') }}
            </label>

            <x-ui.button type="submit" class="w-full" wire:loading.attr="disabled" wire:target="login">
                {{ __('Log in') }}
            </x-ui.button>
        </form>
    </x-ui.card>
</div>
