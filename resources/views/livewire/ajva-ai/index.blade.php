<div>
    {{-- While a question is being answered in the background, ask the server every 2 s whether it is done. --}}
    @if($working)<div wire:poll.2s="checkRun" class="hidden" aria-hidden="true"></div>@endif

    <x-ui.page-header :title="'AjvaAI'" :subtitle="__('Ask about sales, receipts, discounts, stock and prices. AjvaAI reads this app\'s data and gives advice.')" />

    @unless($configured)
        <p class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('AjvaAI is not configured: set GEMINI_API_KEY in .env.') }}</p>
    @endunless

    {{-- Input on top --}}
    <form wire:submit="ask" class="mb-6">
        <div class="flex items-end gap-2">
            <div class="min-w-0 flex-1">
                <label for="question" class="sr-only">{{ __('Your question') }}</label>
                <textarea id="question" wire:model="question" rows="2" maxlength="1500" autofocus
                          x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.ask(); }"
                          placeholder="{{ __('Ask AjvaAI… (Enter to send, Shift+Enter for a new line)') }}"
                          class="block w-full resize-none rounded-lg border-slate-300 shadow-sm focus:border-brand-600 focus:ring-brand-600 sm:text-sm"></textarea>
                <x-ui.error name="question" />
            </div>
            <x-ui.button type="submit" :disabled="$working" wire:loading.attr="disabled" wire:target="ask">
                <span wire:loading.remove wire:target="ask">{{ __('Send') }}</span>
                <span wire:loading wire:target="ask">…</span>
            </x-ui.button>
        </div>
        @if($messages !== [])
            <button type="button" wire:click="newChat" class="mt-2 text-xs text-slate-500 underline-offset-2 hover:underline">{{ __('New chat') }}</button>
        @endif
    </form>

    {{-- Working indicator --}}
    @if($working)
    <div class="mb-4 flex flex-col gap-3"
         x-data="{ i: 0, steps: @js([__('AjvaAI is analysing the data…'), __('Choosing the right reports…'), __('Running them on your data…'), __('Preparing advice…')]) }"
         x-init="setInterval(() => i = (i + 1) % steps.length, 2200)">
        <div class="flex items-center gap-3 rounded-2xl border border-brand-100 bg-white px-4 py-3 shadow-sm">
            <span class="relative flex h-8 w-8 shrink-0 items-center justify-center">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-700/25 motion-reduce:animate-none"></span>
                <span class="relative flex h-8 w-8 items-center justify-center rounded-full bg-brand-700 text-white"><x-icon name="sparkles" class="h-4 w-4 animate-pulse motion-reduce:animate-none" /></span>
            </span>
            <span class="flex items-center gap-1" aria-hidden="true">
                <span class="h-2 w-2 animate-bounce rounded-full bg-brand-700 motion-reduce:animate-none"></span>
                <span class="h-2 w-2 animate-bounce rounded-full bg-brand-700 motion-reduce:animate-none" style="animation-delay: 150ms"></span>
                <span class="h-2 w-2 animate-bounce rounded-full bg-brand-700 motion-reduce:animate-none" style="animation-delay: 300ms"></span>
            </span>
            <span class="text-sm text-slate-600" role="status" x-text="steps[i]"></span>
        </div>
        {{-- Skeleton of the coming answer --}}
        <div class="animate-pulse space-y-2 rounded-2xl border border-slate-200 bg-white p-4 motion-reduce:animate-none" aria-hidden="true">
            <div class="h-3 w-3/4 rounded bg-slate-200"></div>
            <div class="h-3 w-full rounded bg-slate-100"></div>
            <div class="h-3 w-5/6 rounded bg-slate-100"></div>
            <div class="mt-3 h-16 w-full rounded bg-slate-100"></div>
        </div>
    </div>
    @endif

    @if($error)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
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
        <x-ui.card>
            <p class="text-sm text-slate-600">{{ __('Try one of these:') }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach($suggestions as $i => $text)
                    <button type="button" wire:click="useSuggestion({{ $i }})"
                            class="rounded-full border border-slate-300 bg-white px-3 py-1.5 text-left text-xs text-slate-700 hover:border-brand-600 hover:text-brand-700">{{ $text }}</button>
                @endforeach
            </div>
        </x-ui.card>
    @else
        {{-- Newest exchange first, because the input is on top --}}
        <div class="space-y-4">
            @foreach(array_reverse($messages, true) as $i => $message)
                <div wire:key="msg-{{ $i }}" class="flex {{ $message['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                    @if($message['role'] === 'user')
                        <div class="max-w-[90%] whitespace-pre-line rounded-2xl rounded-br-sm bg-brand-700 px-4 py-2.5 text-sm text-white md:max-w-[75%]">{{ $message['text'] }}</div>
                    @else
                        <div class="w-full max-w-full space-y-3 md:max-w-[90%]">
                        @if(filled($message['text']))
                        <div class="overflow-x-auto rounded-2xl rounded-bl-sm border border-slate-200 bg-white px-4 py-3 text-sm leading-relaxed text-slate-800 shadow-sm
                                    [&_p]:mb-2 [&_p:last-child]:mb-0 [&_ul]:mb-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:mb-2 [&_ol]:list-decimal [&_ol]:pl-5
                                    [&_strong]:font-semibold [&_h1]:mb-1 [&_h1]:font-semibold [&_h2]:mb-1 [&_h2]:font-semibold [&_h3]:mb-1 [&_h3]:font-semibold
                                    [&_table]:my-2 [&_table]:w-full [&_th]:border-b [&_th]:border-slate-200 [&_th]:px-2 [&_th]:py-1 [&_th]:text-left [&_td]:border-b [&_td]:border-slate-100 [&_td]:px-2 [&_td]:py-1 [&_code]:rounded [&_code]:bg-slate-100 [&_code]:px-1">
                            {!! \Illuminate\Support\Str::markdown($message['text'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                        </div>
                        @endif

                        {{-- Results are produced and shown locally; they are never sent to the AI. --}}
                        @foreach($message['sections'] ?? [] as $section)
                            <x-ui.card padding="p-0" class="overflow-hidden">
                                <div class="border-b border-slate-100 bg-sand-50 px-4 py-2">
                                    <p class="font-mono text-xs font-semibold text-slate-700">{{ $section['tool'] }}</p>
                                    @if($section['args'] !== '')
                                        <p class="text-xs text-slate-500">{{ $section['args'] }}</p>
                                    @endif
                                </div>
                                @if($section['error'])
                                    <p class="px-4 py-3 text-sm text-red-700">{{ $section['error'] }}</p>
                                @endif
                                @foreach($section['notes'] as $note)
                                    <p class="border-b border-slate-100 px-4 py-2 text-sm {{ $note['level'] === 'warn' ? 'bg-amber-50 text-amber-900' : 'bg-brand-50 text-slate-800' }}">
                                        <span class="font-semibold">{{ $note['level'] === 'warn' ? __('Attention') : __('Advice') }}:</span> {{ $note['text'] }}
                                    </p>
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
                                            <div class="max-h-80 overflow-auto">
                                                <table class="min-w-full text-sm">
                                                    <thead class="sticky top-0 bg-white"><tr>
                                                        @foreach($block['columns'] as $col)<th class="whitespace-nowrap border-b border-slate-200 px-2 py-1 text-left text-xs font-semibold text-slate-500">{{ $col }}</th>@endforeach
                                                    </tr></thead>
                                                    <tbody>
                                                        @foreach($block['rows'] as $row)
                                                            <tr>@foreach($row as $cell)<td class="whitespace-nowrap border-b border-slate-50 px-2 py-1 tabular-nums">{{ $cell }}</td>@endforeach</tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </x-ui.card>
                        @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
