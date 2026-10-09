@php
    $md = 'text-sm leading-relaxed text-slate-800
        [&_p]:mb-2 [&_p:last-child]:mb-0 [&_ul]:mb-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:mb-2 [&_ol]:list-decimal [&_ol]:pl-5
        [&_strong]:font-semibold [&_h1]:mb-1 [&_h1]:font-semibold [&_h2]:mb-1 [&_h2]:font-semibold [&_h3]:mb-1 [&_h3]:font-semibold
        [&_table]:my-2 [&_table]:w-full [&_th]:border-b [&_th]:border-slate-200 [&_th]:px-2 [&_th]:py-1 [&_th]:text-left [&_td]:border-b [&_td]:border-slate-100 [&_td]:px-2 [&_td]:py-1 [&_code]:rounded [&_code]:bg-slate-100 [&_code]:px-1';
    $suggestionIcons = ['chart', 'tag', 'box'];
@endphp
<div>
    {{-- While a question is being answered in the background, ask the server every 2 s whether it is done. --}}
    @if($working)<div wire:poll.2s="checkRun" class="hidden" aria-hidden="true"></div>@endif

    {{-- Hero --}}
    <header class="ajva-aurora relative mb-5 overflow-hidden rounded-2xl bg-gradient-to-br from-brand-800 via-brand-700 to-brand-500 p-5 text-white shadow-lg sm:p-6">
        <span class="pointer-events-none absolute -right-10 -top-12 h-44 w-44 rounded-full bg-white/10 blur-2xl"></span>
        <span class="pointer-events-none absolute -bottom-16 left-1/3 h-40 w-40 rounded-full bg-brand-300/20 blur-2xl"></span>
        <div class="relative flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <span class="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white/15 ring-1 ring-white/30 backdrop-blur">
                    <x-icon name="sparkles" class="h-6 w-6" />
                </span>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-semibold tracking-tight">AjvaAI</h1>
                        <span class="rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider ring-1 ring-white/25">Gemini Flash</span>
                    </div>
                    <p class="mt-0.5 max-w-xl text-sm text-white/80">{{ __('Ask about sales, receipts, discounts, stock and prices. AjvaAI reads this app\'s data and gives advice.') }}</p>
                </div>
            </div>
            <div class="flex flex-col items-start gap-1.5 text-xs sm:items-end">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-black/15 px-2.5 py-1 ring-1 ring-white/20">
                    <span class="relative flex h-2 w-2">
                        <span class="absolute inline-flex h-full w-full rounded-full {{ $configured ? 'bg-emerald-300' : 'bg-amber-300' }} opacity-70 {{ $configured ? 'animate-ping' : '' }} motion-reduce:animate-none"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full {{ $configured ? 'bg-emerald-300' : 'bg-amber-300' }}"></span>
                    </span>
                    {{ $configured ? __('Ready') : __('Not configured') }}
                </span>
                <span class="inline-flex items-center gap-1.5 text-white/75">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                    {{ __('Your data stays on your server') }}
                </span>
            </div>
        </div>
    </header>

    @unless($configured)
        <p class="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">{{ __('AjvaAI is not configured: set GEMINI_API_KEY in .env.') }}</p>
    @endunless

    {{-- Composer (input on top) --}}
    <form wire:submit="ask" class="mb-6">
        <div class="ajva-aurora rounded-2xl bg-gradient-to-r from-brand-300 via-brand-600 to-brand-300 p-[1.5px] shadow-sm">
            <div class="rounded-[calc(1rem-1.5px)] bg-white">
                <label for="question" class="sr-only">{{ __('Your question') }}</label>
                <textarea id="question" wire:model="question" rows="2" maxlength="1500" autofocus
                          x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.ask(); }"
                          placeholder="{{ __('Ask AjvaAI… (Enter to send, Shift+Enter for a new line)') }}"
                          class="block w-full resize-none rounded-t-[calc(1rem-1.5px)] border-0 bg-transparent px-4 pt-3.5 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0"></textarea>
                <div class="flex items-center justify-between gap-3 px-3 pb-3 pt-1">
                    <div class="flex items-center gap-3 text-xs text-slate-400">
                        <span class="hidden sm:inline">{{ __('Enter to send · Shift+Enter for a new line') }}</span>
                        @if($messages !== [])
                            <button type="button" wire:click="newChat" class="rounded-md px-2 py-1 font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-700">{{ __('New chat') }}</button>
                        @endif
                    </div>
                    <button type="submit" @disabled($working) wire:loading.attr="disabled" wire:target="ask"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-brand-600 to-brand-800 text-white shadow transition hover:scale-105 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-brand-600 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:scale-100 motion-reduce:transition-none">
                        <span class="sr-only">{{ __('Send') }}</span>
                        <svg wire:loading.remove wire:target="ask" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19.5V4.5m0 0l-6 6m6-6l6 6" /></svg>
                        <span wire:loading wire:target="ask" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white motion-reduce:animate-none"></span>
                    </button>
                </div>
            </div>
        </div>
        <x-ui.error name="question" />
    </form>

    {{-- Thinking --}}
    @if($working)
    <div class="ajva-rise mb-5 flex items-start gap-3"
         x-data="{ i: 0, steps: @js([__('AjvaAI is analysing the data…'), __('Choosing the right reports…'), __('Running them on your data…'), __('Preparing advice…')]) }"
         x-init="setInterval(() => i = (i + 1) % steps.length, 2200)">
        <span class="relative flex h-9 w-9 shrink-0 items-center justify-center">
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-600/30 motion-reduce:animate-none"></span>
            <span class="ajva-aurora relative flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-brand-800 text-white"><x-icon name="sparkles" class="h-4 w-4 animate-pulse motion-reduce:animate-none" /></span>
        </span>
        <div class="min-w-0 flex-1 space-y-2">
            <div class="inline-flex items-center gap-3 rounded-2xl rounded-tl-sm border border-brand-100 bg-white px-4 py-2.5 shadow-sm">
                <span class="flex items-center gap-1" aria-hidden="true">
                    <span class="h-2 w-2 animate-bounce rounded-full bg-brand-600 motion-reduce:animate-none"></span>
                    <span class="h-2 w-2 animate-bounce rounded-full bg-brand-600 motion-reduce:animate-none" style="animation-delay: 150ms"></span>
                    <span class="h-2 w-2 animate-bounce rounded-full bg-brand-600 motion-reduce:animate-none" style="animation-delay: 300ms"></span>
                </span>
                <span class="text-sm text-slate-600" role="status" x-text="steps[i]"></span>
            </div>
            <div class="animate-pulse space-y-2 rounded-2xl border border-slate-200 bg-white p-4 motion-reduce:animate-none" aria-hidden="true">
                <div class="h-3 w-3/4 rounded bg-slate-200"></div>
                <div class="h-3 w-full rounded bg-slate-100"></div>
                <div class="h-3 w-5/6 rounded bg-slate-100"></div>
                <div class="mt-3 h-16 w-full rounded bg-slate-100"></div>
            </div>
        </div>
    </div>
    @endif

    @if($error)
        <div class="ajva-rise mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200">
            <p>{{ $error }}</p>
            @if($errorDetails)
                <button type="button" wire:click="openErrorDetails" class="rounded-md border border-red-300 bg-white px-2.5 py-1 text-xs font-medium text-red-800 hover:bg-red-100">{{ __('Show full error') }}</button>
            @endif
        </div>
    @endif

    {{-- The AI service's full error text --}}
    <x-ui.modal :show="$showErrorDetails && $errorDetails" :title="__('Full error from the AI service')" class="max-w-3xl" close="closeErrorDetails" wire:keydown.escape.window="closeErrorDetails">
        @if($error)<p class="mb-3 text-sm text-red-800">{{ $error }}</p>@endif
        <div x-data="{ copied: false }">
            <pre x-ref="text" class="max-h-[50vh] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-900 p-3 text-xs leading-relaxed text-slate-100">{{ $errorDetails }}</pre>
            <div class="mt-3 flex justify-end gap-2">
                <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                        x-on:click="navigator.clipboard.writeText($refs.text.innerText).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                        x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
                <button type="button" wire:click="closeErrorDetails" class="rounded-lg bg-brand-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-800">{{ __('Close') }}</button>
            </div>
        </div>
    </x-ui.modal>

    @if($messages === [])
        {{-- Empty state: suggested questions --}}
        <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Try one of these:') }}</p>
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach($suggestions as $i => $text)
                <button type="button" wire:click="useSuggestion({{ $i }})" wire:key="sug-{{ $i }}" style="animation-delay: {{ $i * 80 }}ms"
                        class="ajva-rise group flex flex-col items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-brand-400 hover:shadow-md motion-reduce:transition-none motion-reduce:hover:translate-y-0">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-700 ring-1 ring-brand-100 transition group-hover:bg-brand-700 group-hover:text-white"><x-icon name="{{ $suggestionIcons[$i % 3] }}" class="h-5 w-5" /></span>
                    <span class="text-sm leading-snug text-slate-700">{{ $text }}</span>
                </button>
            @endforeach
        </div>
    @else
        {{-- Newest exchange first, because the input is on top --}}
        <div class="space-y-5">
            @foreach(array_reverse($messages, true) as $i => $message)
                <div wire:key="msg-{{ $i }}" class="ajva-rise flex items-start gap-3 {{ $message['role'] === 'user' ? 'flex-row-reverse' : '' }}">
                    @if($message['role'] === 'user')
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold uppercase text-slate-600">{{ mb_substr(auth()->user()->name ?? 'U', 0, 1) }}</span>
                        <div class="max-w-[85%] whitespace-pre-line rounded-2xl rounded-tr-sm bg-gradient-to-br from-brand-600 to-brand-800 px-4 py-2.5 text-sm text-white shadow-sm md:max-w-[70%]">{{ $message['text'] }}</div>
                    @else
                        <span class="ajva-aurora flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-brand-800 text-white shadow-sm"><x-icon name="sparkles" class="h-4 w-4" /></span>
                        <div class="min-w-0 flex-1 space-y-3 md:max-w-[92%]">
                            <p class="text-xs font-semibold text-brand-700">AjvaAI</p>
                            @if(filled($message['text']))
                                <div class="overflow-x-auto rounded-2xl rounded-tl-sm border border-brand-100 bg-white px-4 py-3 shadow-sm {{ $md }}">
                                    {!! \Illuminate\Support\Str::markdown($message['text'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                                </div>
                            @endif

                            {{-- Results are produced and shown locally; they are never sent to the AI. --}}
                            @foreach($message['sections'] ?? [] as $section)
                                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-gradient-to-r from-brand-50 to-white px-4 py-2">
                                        <div class="min-w-0">
                                            <p class="font-mono text-xs font-semibold text-slate-700">{{ $section['tool'] }}</p>
                                            @if($section['args'] !== '')
                                                <p class="truncate text-xs text-slate-500">{{ $section['args'] }}</p>
                                            @endif
                                        </div>
                                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-800">
                                            <x-icon name="archive" class="h-3 w-3" />{{ __('Data from your system') }}
                                        </span>
                                    </div>
                                    @if($section['error'])
                                        <p class="px-4 py-3 text-sm text-red-700">{{ $section['error'] }}</p>
                                    @endif
                                    @foreach($section['notes'] as $note)
                                        <div class="flex items-start gap-2.5 border-b border-slate-100 border-l-4 px-4 py-2.5 text-sm {{ $note['level'] === 'warn' ? 'border-l-amber-400 bg-amber-50 text-amber-900' : 'border-l-brand-500 bg-brand-50/70 text-slate-800' }}">
                                            @if($note['level'] === 'warn')
                                                <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                                            @else
                                                <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
                                            @endif
                                            <p><span class="font-semibold">{{ $note['level'] === 'warn' ? __('Attention') : __('Advice') }}:</span> {{ $note['text'] }}</p>
                                        </div>
                                    @endforeach
                                    @foreach($section['blocks'] as $block)
                                        <div class="px-4 py-3">
                                            @if(filled($block['title']))
                                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $block['title'] }}</p>
                                            @endif
                                            @if($block['type'] === 'kv')
                                                <dl class="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                                                    @foreach($block['rows'] as [$k, $v])
                                                        <div class="flex justify-between gap-3 border-b border-slate-50 py-0.5"><dt class="text-slate-500">{{ $k }}</dt><dd class="text-right tabular-nums text-slate-900">{{ $v }}</dd></div>
                                                    @endforeach
                                                </dl>
                                            @else
                                                <div class="max-h-80 overflow-auto rounded-lg ring-1 ring-slate-100">
                                                    <table class="min-w-full text-sm">
                                                        <thead class="sticky top-0 bg-sand-50"><tr>
                                                            @foreach($block['columns'] as $col)<th class="whitespace-nowrap border-b border-slate-200 px-2 py-1.5 text-left text-xs font-semibold text-slate-500">{{ $col }}</th>@endforeach
                                                        </tr></thead>
                                                        <tbody class="[&_tr:hover]:bg-brand-50/50">
                                                            @foreach($block['rows'] as $row)
                                                                <tr>@foreach($row as $cell)<td class="whitespace-nowrap border-b border-slate-50 px-2 py-1 tabular-nums">{{ $cell }}</td>@endforeach</tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
