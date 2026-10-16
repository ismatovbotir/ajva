{{-- Monitor builder ("constructor"): a 16:9 TV frame with the REAL widget components filled with generated sample data
     (no queries), an overlay layer for drag / resize, a widget palette (drag onto the canvas or tap) and a side panel.
     Drag / resize is the Alpine component "monitorCanvas" (resources/js/monitor-builder.js); the server snaps, clamps and
     resolves collisions. The number inputs of the side panel and the arrow keys are the keyboard / touch alternative.
     Preview mode draws the same frame with real data (as the signed-in admin or as the public link). --}}
@php
    $costVariant = ['cheap' => 'success', 'medium' => 'info', 'expensive' => 'warning'];
    $costLabel = ['cheap' => __('Cheap'), 'medium' => __('Medium'), 'expensive' => __('Expensive')];
    $rowLabels = [0 => __('Auto (rows used by the layout)')] + collect($rowChoices)->mapWithKeys(fn ($n) => [$n => match (true) {
        $n <= 8 => __('Tall rows (:n rows)', ['n' => $n]),
        $n <= 12 => __('Normal rows (:n rows)', ['n' => $n]),
        default => __('Compact rows (:n rows)', ['n' => $n]),
    }])->all();
@endphp
<div class="space-y-4" x-data="monitorCanvas({ cols: 12 })"
     @pointermove.window="drag($event)" @pointerup.window="end()" @pointercancel.window="cancel()" @keydown.window="shortcut($event)">
    <x-ui.page-header :title="__('Monitor builder')" :subtitle="$monitorName">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
            <x-ui.button variant="secondary" :href="route('settings.monitors')">{{ __('Back to monitors') }}</x-ui.button>
            <x-ui.button variant="secondary" wire:click="undo" :disabled="empty($history)" title="Ctrl+Z">{{ __('Undo') }}</x-ui.button>
            <x-ui.button variant="secondary" wire:click="redo" :disabled="empty($future)" title="Ctrl+Shift+Z">{{ __('Redo') }}</x-ui.button>
            <x-ui.button variant="secondary" wire:click="togglePreview">{{ $preview ? __('Edit layout') : __('Preview with real data') }}</x-ui.button>
            <x-ui.button wire:click="save">{{ __('Save') }}@if($dirty) *@endif</x-ui.button>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('status') && ! $dirty)
        <p class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800" role="status">{{ session('status') }}</p>
    @endif

    {{-- Limits and live validation --}}
    <div class="flex flex-wrap items-center gap-2 text-xs" aria-label="{{ __('Limits') }}">
        @foreach([__('Widgets') => $limits['widgets'], __('Expensive') => $limits['expensive'], __('Heatmaps') => $limits['heatmaps']] as $label => [$count, $max])
            <span class="rounded-full px-2.5 py-1 font-medium {{ $count >= $max ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700' }}">{{ $label }}: {{ $count }} / {{ $max }}</span>
        @endforeach
        @if($dirty)
            <span class="rounded-full bg-blue-100 px-2.5 py-1 font-medium text-blue-800">{{ __('Unsaved changes') }}</span>
        @endif
    </div>
    @if($notices !== [] || $problems !== [])
        <ul class="space-y-1 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800" role="alert">
            @foreach(array_unique([...$notices, ...$problems]) as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif

    {{-- Canvas toolbar --}}
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
        @if($preview)
            <label for="previewAs" class="font-medium text-slate-700">{{ __('Preview as') }}</label>
            <select id="previewAs" wire:model.live="previewAs" class="rounded-lg border-slate-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                <option value="me">{{ __('Me (signed in)') }}</option>
                <option value="public">{{ __('Public link') }}</option>
            </select>
            <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-800">{{ __('Real data of the monitor shops') }}</span>
            <span class="text-slate-500">{{ __('Unsaved changes are included.') }}</span>
        @else
            <span class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800" title="{{ __('The numbers on the canvas are invented, so dragging is instant and works without data. Use Preview with real data to see real numbers.') }}">{{ __('Sample data') }}</span>
        @endif
        <label class="flex items-center gap-2 text-slate-700">
            <span class="font-medium">{{ __('Row height') }}</span>
            <select wire:model.live="rowsSetting" aria-label="{{ __('Row height') }}" class="rounded-lg border-slate-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                @foreach($rowLabels as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex items-center gap-2 text-slate-700">
            <span class="font-medium">{{ __('Zoom') }}</span>
            <select wire:model.live="zoom" aria-label="{{ __('Zoom') }}" class="rounded-lg border-slate-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                <option value="fit">{{ __('Fit to page') }}</option>
                <option value="1">100%</option>
                <option value="0.75">75%</option>
            </select>
        </label>
        <label class="flex items-center gap-2 text-slate-700">
            <span class="font-medium">{{ __('Theme') }}</span>
            <select x-model="theme" @change="saveTheme()" aria-label="{{ __('Theme') }}" data-mon-select class="rounded-lg border-slate-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                <option value="dark">{{ __('Dark theme') }}</option>
                <option value="light">{{ __('Light theme') }}</option>
            </select>
        </label>
        <span class="text-xs text-slate-500">{{ __('The frame is a 16:9 TV screen; the grid has :rows rows.', ['rows' => $rows]) }}</span>
    </div>

    {{-- Stage: the TV frame, scaled to the page --}}
    <div x-ref="stage" class="overflow-x-auto rounded-xl bg-slate-200 p-2" wire:ignore.self>
        <div :style="{ width: (frameW * scale) + 'px', height: (frameH * scale + 56) + 'px' }" wire:ignore.self>
            <div x-ref="frame" wire:ignore.self :data-mon-theme="theme"
                 :style="{ transform: 'scale(' + scale + ')', '--s': scale }"
                 class="mon-frame flex flex-col gap-[0.9rem] rounded-[1rem] border border-slate-700 bg-slate-950 p-[1rem] text-slate-100 antialiased" style="font-size: 16px;">
                {{-- The header strip of the real screen (same height), so the grid below gets the same room --}}
                <div class="flex h-[4rem] shrink-0 items-center justify-between rounded-2xl border border-slate-800 bg-slate-900 px-[1.2rem]" aria-hidden="true">
                    <div class="flex items-center gap-[0.9rem]">
                        <span class="flex h-[2.6rem] w-[2.6rem] items-center justify-center rounded-xl bg-brand-600 text-[1.4rem] font-bold text-white">{{ mb_substr(config('app.name'), 0, 1) }}</span>
                        <div class="leading-tight">
                            <p class="text-[1.4rem] font-semibold text-white">{{ config('app.name') }}</p>
                            <p class="text-[0.95rem] text-slate-400">{{ $monitorName }}</p>
                        </div>
                    </div>
                    <span class="text-[2.6rem] font-bold leading-none text-white tabular-nums">14:30:00</span>
                    <span class="inline-flex items-center gap-2 rounded-lg bg-emerald-500/15 px-[0.7rem] py-[0.3rem] text-[1rem] font-semibold text-emerald-300"><span class="h-[0.7rem] w-[0.7rem] rounded-full bg-emerald-400"></span>{{ __('Live') }}</span>
                </div>

                <div class="min-h-0 flex-1">
                    @if($preview)
                        <x-monitor.grid :widgets="$screen['widgets']" :rows="$rowsSetting ?: null" />
                    @else
                        <x-monitor.grid :widgets="$screen['widgets']" :rows="$rowsSetting ?: null" wire:click.self="select(null)">
                            @foreach($widgets as $widget)
                                @php
                                    $def = \App\Monitor\WidgetCatalog::find($widget['key']);
                                    $isSelected = $selectedId === $widget['id'];
                                    $title = ($widget['options']['title'] ?? '') !== '' ? $widget['options']['title'] : ($def?->title() ?? $widget['key']);
                                @endphp
                                <div wire:key="ov-{{ $widget['id'] }}" class="mon-overlay {{ $isSelected ? 'is-selected' : '' }}"
                                     style="--gc: {{ $widget['x'] + 1 }} / span {{ $widget['w'] }}; --gr: {{ $widget['y'] + 1 }} / span {{ $widget['h'] }};"
                                     data-widget-id="{{ $widget['id'] }}" data-x="{{ $widget['x'] }}" data-y="{{ $widget['y'] }}" data-w="{{ $widget['w'] }}" data-h="{{ $widget['h'] }}"
                                     data-min-w="{{ $def?->min[0] ?? 1 }}" data-max-w="{{ $def?->max[0] ?? 12 }}" data-min-h="{{ $def?->min[1] ?? 1 }}" data-max-h="{{ $def?->max[1] ?? 12 }}"
                                     tabindex="0" role="button" aria-pressed="{{ $isSelected ? 'true' : 'false' }}" aria-label="{{ $title }}, {{ $widget['w'] }} x {{ $widget['h'] }}"
                                     title="{{ __('Drag to move') }}"
                                     @pointerdown="begin($event, 'move')"
                                     wire:click="select('{{ $widget['id'] }}')"
                                     wire:keydown.arrow-left.prevent="nudge('{{ $widget['id'] }}', -1, 0, $event.shiftKey)"
                                     wire:keydown.arrow-right.prevent="nudge('{{ $widget['id'] }}', 1, 0, $event.shiftKey)"
                                     wire:keydown.arrow-up.prevent="nudge('{{ $widget['id'] }}', 0, -1, $event.shiftKey)"
                                     wire:keydown.arrow-down.prevent="nudge('{{ $widget['id'] }}', 0, 1, $event.shiftKey)"
                                     wire:keydown.delete.prevent="remove('{{ $widget['id'] }}')">
                                    @foreach(['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'] as $edge)
                                        <span class="mon-h" data-edge="{{ $edge }}" aria-hidden="true" @pointerdown.stop="begin($event, 'resize', '{{ $edge }}')"><i></i></span>
                                    @endforeach
                                    @if($isSelected)
                                        <span class="mon-size" x-show="! mode">{{ $widget['w'] }} &times; {{ $widget['h'] }}</span>
                                        <span class="mon-tools" @pointerdown.stop>
                                            <button type="button" wire:click.stop="duplicate('{{ $widget['id'] }}')" title="{{ __('Duplicate') }}" aria-label="{{ __('Duplicate') }}">&#10697;</button>
                                            <button type="button" class="danger" wire:click.stop="remove('{{ $widget['id'] }}')" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">&times;</button>
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                            <div class="mon-ghost" wire:ignore :style="ghostStyle()" style="display: none;"><span class="mon-badge" x-text="ghost ? ghost.w + ' × ' + ghost.h : ''"></span></div>
                        </x-monitor.grid>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @unless($preview)
        <p class="text-xs text-slate-500">{{ __('Drag a tile to move it, drag its edges or corners to resize, drag an item from the list onto the canvas to add it. Keyboard: select a tile, arrow keys move it, Shift + arrows resize it, Delete removes it, Ctrl+Z undoes.') }}</p>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
            {{-- Palette --}}
            <x-ui.card padding="p-3" class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-slate-900">{{ __('Widgets') }}</h2>
                    <input type="search" wire:model.live.debounce.250ms="search" placeholder="{{ __('Search widgets') }}" aria-label="{{ __('Search widgets') }}"
                           class="w-full max-w-xs rounded-lg border-slate-300 text-sm focus:border-brand-600 focus:ring-brand-600" />
                </div>
                <div class="max-h-[22rem] space-y-3 overflow-y-auto pr-1">
                    @forelse($groups as $group => $defs)
                        <section wire:key="palette-{{ $group }}">
                            <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $group }}</h3>
                            <ul class="grid gap-1.5 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach($defs as $def)
                                    <li wire:key="palette-{{ $def->key }}">
                                        <button type="button" data-label="{{ $def->title() }}" title="{{ __('Click to add, or drag onto the canvas') }}"
                                                @pointerdown="beginPalette($event, '{{ $def->key }}', {{ $def->w }}, {{ $def->h }})" @click="add('{{ $def->key }}')"
                                                class="h-full w-full cursor-grab rounded-lg border border-slate-200 bg-white p-2 text-left text-sm hover:border-brand-600 hover:bg-sand-50 focus:outline-none focus:ring-2 focus:ring-brand-500">
                                            <span class="flex items-center justify-between gap-2 font-medium text-slate-900"><span>{{ $def->title() }}</span><span class="shrink-0 rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">+ {{ __('Add') }}</span></span>
                                            <span class="mt-0.5 block text-xs text-slate-500">{{ $def->summary() }}</span>
                                            <span class="mt-1 flex flex-wrap gap-1">
                                                <x-ui.badge :variant="$costVariant[$def->cost]">{{ $costLabel[$def->cost] }}</x-ui.badge>
                                                @if($def->profit)<x-ui.badge variant="danger">{{ __('Profit only') }}</x-ui.badge>@endif
                                                @unless($def->publicSafe)<x-ui.badge>{{ __('Not on public links') }}</x-ui.badge>@endunless
                                                @foreach($def->diagrams as $diagram)
                                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[0.65rem] text-slate-600">{{ $diagram->label() }}</span>
                                                @endforeach
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('No widgets match your search.') }}</p>
                    @endforelse
                </div>
            </x-ui.card>

            {{-- Side panel --}}
            <x-ui.card padding="p-3" class="space-y-3 self-start">
                @if($selected && $selectedDef)
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">{{ $selectedDef->title() }}</h2>
                        <p class="text-xs text-slate-500">{{ $selectedDef->summary() }}</p>
                        @if($selectedDef->rule)
                            <p class="mt-1 text-xs text-slate-500">{{ __($selectedDef->rule, ['days' => $horizon]) }}</p>
                        @endif
                    </div>

                    <div class="space-y-3">
                        <x-ui.select name="panelDiagram" :label="__('Diagram')" wire:model="panelDiagram" wire:change="applyPanel">
                            @foreach($selectedDef->diagrams as $diagram)
                                <option value="{{ $diagram->value }}">{{ $diagram->label() }}</option>
                            @endforeach
                        </x-ui.select>

                        <x-ui.input name="panelTitle" :label="__('Title')" wire:model="panelTitle" wire:change="applyPanel" maxlength="60" :placeholder="$selectedDef->title()" />

                        @if(in_array('top_n', $selectedDef->options, true))
                            <x-ui.input name="panelTopN" type="number" :label="__('Top N (max 15)')" wire:model="panelTopN" wire:change="applyPanel" min="1" max="{{ \App\Monitor\WidgetCatalog::TOP_N_MAX }}" />
                        @endif
                        @if(in_array('metric', $selectedDef->options, true))
                            <x-ui.select name="panelMetric" :label="__('Measure')" wire:model="panelMetric" wire:change="applyPanel">
                                <option value="sum">{{ __('Sales total') }}</option>
                                <option value="count">{{ __('Receipts') }}</option>
                            </x-ui.select>
                        @endif

                        <fieldset>
                            <legend class="text-sm font-medium text-slate-700">{{ __('Position and size') }}</legend>
                            <div class="mt-1 grid grid-cols-2 gap-2">
                                <x-ui.input name="panelX" type="number" :label="__('Column (x)')" wire:model="panelX" wire:change="applyPanel" min="0" max="11" />
                                <x-ui.input name="panelY" type="number" :label="__('Row (y)')" wire:model="panelY" wire:change="applyPanel" min="0" />
                                <x-ui.input name="panelW" type="number" :label="__('Width (w)')" wire:model="panelW" wire:change="applyPanel" min="{{ $selectedDef->min[0] }}" max="{{ $selectedDef->max[0] }}" />
                                <x-ui.input name="panelH" type="number" :label="__('Height (h)')" wire:model="panelH" wire:change="applyPanel" min="{{ $selectedDef->min[1] }}" max="{{ $selectedDef->max[1] }}" />
                            </div>
                            <p class="mt-1 text-xs text-slate-500">{{ __('Size :min to :max cells on a 12-column grid.', ['min' => $selectedDef->min[0].'x'.$selectedDef->min[1], 'max' => $selectedDef->max[0].'x'.$selectedDef->max[1]]) }}</p>
                        </fieldset>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button variant="secondary" wire:click="duplicate('{{ $selected['id'] }}')">{{ __('Duplicate') }}</x-ui.button>
                        <x-ui.button variant="danger" wire:click="remove('{{ $selected['id'] }}')">{{ __('Delete') }}</x-ui.button>
                    </div>
                @elseif($selected)
                    <p class="text-sm text-slate-600">{{ __('This metric no longer exists in the catalog.') }}</p>
                    <x-ui.button variant="danger" wire:click="remove('{{ $selected['id'] }}')">{{ __('Delete') }}</x-ui.button>
                @else
                    <p class="text-sm text-slate-500">{{ __('Select a widget to change its diagram, title, size and position.') }}</p>
                @endif
            </x-ui.card>
        </div>
    @endunless

    {{-- The widget name that follows the pointer while an item is dragged from the list --}}
    <div x-show="mode === 'palette'" style="display: none;" wire:ignore
         :style="{ left: floating ? (floating.x + 14) + 'px' : '0', top: floating ? (floating.y + 14) + 'px' : '0' }"
         class="pointer-events-none fixed z-50 rounded-lg px-3 py-1.5 text-sm font-semibold text-white shadow-lg"
         :class="overCanvas ? 'bg-brand-700' : 'bg-slate-500'" x-text="origin ? origin.label : ''"></div>
</div>
