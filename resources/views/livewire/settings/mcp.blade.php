@php
    $ready = $enabled && ($hasStoredToken || $hasEnvToken);
    $tokenPlaceholder = $newToken ?? '<YOUR_TOKEN>';
@endphp
<div class="space-y-6">
    <x-ui.page-header :title="__('MCP server')" :subtitle="__('Let AI assistants read sales, stock and receipts through a read-only endpoint.')" />

    {{-- Status --}}
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-semibold text-slate-900">{{ __('Status') }}</h2>
                    @if($ready)
                        <x-ui.badge variant="success">{{ __('Ready') }}</x-ui.badge>
                    @elseif(! $enabled)
                        <x-ui.badge variant="warning">{{ __('Disabled') }}</x-ui.badge>
                    @else
                        <x-ui.badge variant="danger">{{ __('No token yet') }}</x-ui.badge>
                    @endif
                </div>
                <p class="mt-1 text-sm text-slate-500">{{ __('The endpoint answers only while it is enabled and a token exists.') }}</p>
            </div>

            <button type="button" role="switch" aria-checked="{{ $enabled ? 'true' : 'false' }}" wire:click="toggleEnabled"
                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $enabled ? 'bg-brand-700' : 'bg-slate-300' }}">
                <span class="sr-only">{{ __('Enable MCP server') }}</span>
                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition {{ $enabled ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
            </button>
        </div>

        <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Endpoint') }}</dt>
                <p class="mb-1 text-xs text-slate-500">{{ __('The address is built from APP_URL in the server .env:') }} <code>{{ $appUrl }}</code></p>
                @if($appUrlLooksLocal)
                    <p class="mb-1 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('APP_URL points to localhost, so this address will not work from other computers. Set APP_URL to the public address of this server in .env and clear the config cache.') }}</p>
                @endif
                <dd class="mt-1 flex items-center gap-2" x-data="{ copied: false }">
                    <code class="min-w-0 flex-1 truncate rounded bg-sand-50 px-2 py-1 text-sm text-slate-800">{{ $endpoint }}</code>
                    <button type="button" class="shrink-0 text-xs font-medium text-brand-700 hover:underline"
                            @click="navigator.clipboard.writeText(@js($endpoint)); copied = true; setTimeout(() => copied = false, 1500)"
                            x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Token') }}</dt>
                <dd class="mt-1 text-sm text-slate-800">
                    @if($hasStoredToken)
                        {{ __('Generated') }} {{ $tokenCreatedAt }}
                    @else
                        <span class="text-slate-500">{{ __('None generated') }}</span>
                    @endif
                    @if($hasEnvToken)
                        <span class="block text-xs text-slate-500">{{ __('A token from the server .env (MCP_API_TOKEN) is also accepted.') }}</span>
                    @endif
                </dd>
            </div>
        </dl>

        <div class="mt-4">
            <x-ui.button type="button" wire:click="generateToken"
                         wire:confirm="{{ $hasStoredToken ? __('Generate a new token? The current one stops working immediately.') : __('Generate a token?') }}">
                {{ $hasStoredToken ? __('Regenerate token') : __('Generate token') }}
            </x-ui.button>
        </div>

        @if($newToken)
            <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4" x-data="{ copied: false }">
                <p class="text-sm font-medium text-amber-900">{{ __('Copy this token now — it will not be shown again.') }}</p>
                <div class="mt-2 flex items-center gap-2">
                    <code class="min-w-0 flex-1 break-all rounded bg-white px-2 py-1 text-sm text-slate-900">{{ $newToken }}</code>
                    <button type="button" class="shrink-0 rounded-md border border-amber-300 bg-white px-2.5 py-1 text-xs font-medium text-amber-900"
                            @click="navigator.clipboard.writeText(@js($newToken)); copied = true"
                            x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
                </div>
            </div>
        @endif
    </x-ui.card>

    {{-- Tools --}}
    <x-ui.card padding="p-0">
        <div class="px-4 pt-4">
            <h2 class="text-base font-semibold text-slate-900">{{ __('Tools') }}</h2>
            <p class="mb-3 mt-1 text-sm text-slate-500">{{ __('Switch off any tool you do not want assistants to use. All tools are read-only.') }}</p>
        </div>
        <ul class="divide-y divide-slate-100">
            @foreach($tools as $tool)
                <li class="flex items-start justify-between gap-4 px-4 py-3" wire:key="tool-{{ $tool['name'] }}">
                    <div class="min-w-0">
                        <p class="font-mono text-sm font-medium text-slate-900">{{ $tool['name'] }}</p>
                        <p class="text-sm text-slate-500">{{ $tool['description'] }}</p>
                    </div>
                    <button type="button" role="switch" aria-checked="{{ $tool['enabled'] ? 'true' : 'false' }}" wire:click="toggleTool('{{ $tool['name'] }}')"
                            class="relative mt-0.5 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $tool['enabled'] ? 'bg-brand-700' : 'bg-slate-300' }}">
                        <span class="sr-only">{{ $tool['name'] }}</span>
                        <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition {{ $tool['enabled'] ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
                    </button>
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    {{-- Client setup --}}
    <x-ui.card>
        <h2 class="text-base font-semibold text-slate-900">{{ __('Connect a client') }}</h2>
        <p class="mb-3 mt-1 text-sm text-slate-500">{{ __('Example for Claude Code (run in a terminal). Replace the token placeholder with the token you generated.') }}</p>
        <pre class="overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">claude mcp add --transport http ajwa {{ $endpoint }} \
  --header "Authorization: Bearer {{ $tokenPlaceholder }}"</pre>
        <p class="mb-2 mt-4 text-sm text-slate-500">{{ __('Or as a project .mcp.json:') }}</p>
        <pre class="overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{
  "mcpServers": {
    "ajwa": {
      "type": "http",
      "url": "{{ $endpoint }}",
      "headers": { "Authorization": "Bearer {{ $tokenPlaceholder }}" }
    }
  }
}</pre>
    </x-ui.card>
</div>
