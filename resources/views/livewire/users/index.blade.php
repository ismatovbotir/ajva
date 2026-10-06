<div>
    <x-ui.page-header :title="__('Users')" :subtitle="__('Manage who can sign in to the admin panel.')">
        <x-slot:actions>
            <x-ui.button wire:click="create">{{ __('New user') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4">
        <x-ui.input name="search" :label="__('Search')" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search by name or email') }}" />
    </div>

    @error('delete')
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>
    @enderror

    @if($users->isEmpty())
        <x-ui.empty-state :title="__('No users found')" :description="__('Try adjusting your search.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($users as $user)
                <x-ui.card wire:key="user-card-{{ $user->id }}">
                    <div class="flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900">
                                {{ $user->name }}
                                @if($user->id === auth()->id())
                                    <x-ui.badge variant="success">{{ __('You') }}</x-ui.badge>
                                @endif
                            </p>
                            <p class="truncate text-sm text-slate-500">{{ $user->email }}</p>
                            <x-ui.badge :variant="$user->role->badgeVariant()" class="mt-1">{{ $user->role->label() }}</x-ui.badge>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-ui.button variant="secondary" wire:click="edit({{ $user->id }})">{{ __('Edit') }}</x-ui.button>
                            @if($user->id !== auth()->id())
                                <x-ui.button variant="danger" wire:click="delete({{ $user->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
                            @endif
                        </div>
                    </div>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Desktop table --}}
        <x-ui.card padding="p-0" class="hidden md:block">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Name') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Email') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Role') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($users as $user)
                        <tr wire:key="user-row-{{ $user->id }}">
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">
                                {{ $user->name }}
                                @if($user->id === auth()->id())
                                    <x-ui.badge variant="success">{{ __('You') }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $user->email }}</td>
                            <td class="px-4 py-3 text-sm"><x-ui.badge :variant="$user->role->badgeVariant()">{{ $user->role->label() }}</x-ui.badge></td>
                            <td class="px-4 py-3 text-right text-sm">
                                <x-ui.button variant="secondary" wire:click="edit({{ $user->id }})">{{ __('Edit') }}</x-ui.button>
                                @if($user->id !== auth()->id())
                                    <x-ui.button variant="danger" wire:click="delete({{ $user->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $users->links() }}</div>
    @endif

    <x-ui.modal :show="$showModal" :title="$userId ? __('Edit user') : __('New user')">
        <form wire:submit="save" class="space-y-4">
            <x-ui.input name="name" :label="__('Name')" wire:model="name" autofocus />
            <x-ui.input name="email" type="email" :label="__('Email')" wire:model="email" />
            <div>
                <x-ui.input name="password" type="password" :label="__('Password')" wire:model="password" autocomplete="new-password" />
                @if($userId)
                    <p class="mt-1 text-xs text-slate-500">{{ __('Leave blank to keep the current password.') }}</p>
                @endif
            </div>

            <div>
                <x-ui.select name="role" :label="__('Role')" wire:model="role">
                    @foreach($roles as $roleOption)
                        <option value="{{ $roleOption->value }}">{{ $roleOption->label() }}</option>
                    @endforeach
                </x-ui.select>
                <ul class="mt-2 space-y-0.5 text-xs text-slate-500">
                    @foreach($roles as $roleOption)
                        <li><span class="font-medium text-slate-700">{{ $roleOption->label() }}</span> — {{ $roleOption->description() }}</li>
                    @endforeach
                </ul>
            </div>

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="closeModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
