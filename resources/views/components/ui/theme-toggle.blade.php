@props(['label' => false, 'collapsible' => false])
{{-- Light / dark switch. State lives on html[data-theme] + localStorage, so it survives Livewire updates and syncs between tabs. --}}
<button
    type="button"
    x-data="{
        dark: document.documentElement.getAttribute('data-theme') === 'dark',
        apply(theme) {
            this.dark = theme === 'dark';
            document.documentElement.setAttribute('data-theme', theme);
        },
        toggle() {
            const theme = this.dark ? 'light' : 'dark';
            this.apply(theme);
            try { localStorage.setItem('theme', theme); } catch (e) {}
        },
    }"
    @click="toggle()"
    @storage.window="if ($event.key === 'theme' && ['light', 'dark'].includes($event.newValue)) apply($event.newValue)"
    :aria-pressed="dark.toString()"
    aria-label="{{ __('Dark theme') }}"
    :title="dark ? @js(__('Switch to light theme')) : @js(__('Switch to dark theme'))"
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-3 rounded-lg p-2 text-slate-500 hover:bg-slate-200/60 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500']) }}
>
    <span x-show="! dark" class="shrink-0"><x-icon name="moon" class="h-5 w-5" /></span>
    <span x-show="dark" x-cloak class="shrink-0"><x-icon name="sun" class="h-5 w-5" /></span>
    @if($label)
        <span class="truncate text-sm font-medium" @if($collapsible) x-show="!sidebarCollapsed" x-cloak @endif>
            <span x-show="! dark">{{ __('Dark theme') }}</span><span x-show="dark" x-cloak>{{ __('Light theme') }}</span>
        </span>
    @endif
</button>
