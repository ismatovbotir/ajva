<div>
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
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="ask">
                <span wire:loading.remove wire:target="ask">{{ __('Send') }}</span>
                <span wire:loading wire:target="ask">…</span>
            </x-ui.button>
        </div>
        @if($messages !== [])
            <button type="button" wire:click="newChat" class="mt-2 text-xs text-slate-500 underline-offset-2 hover:underline">{{ __('New chat') }}</button>
        @endif
    </form>

    {{-- Working indicator --}}
    <div wire:loading wire:target="ask" class="mb-4 flex items-center gap-2 text-sm text-slate-500">
        <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-brand-700"></span>
        {{ __('AjvaAI is analysing the data…') }}
    </div>

    @if($error)
        <p class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</p>
    @endif

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
                        <div class="max-w-full overflow-x-auto rounded-2xl rounded-bl-sm border border-slate-200 bg-white px-4 py-3 text-sm leading-relaxed text-slate-800 shadow-sm md:max-w-[85%]
                                    [&_p]:mb-2 [&_p:last-child]:mb-0 [&_ul]:mb-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:mb-2 [&_ol]:list-decimal [&_ol]:pl-5
                                    [&_strong]:font-semibold [&_h1]:mb-1 [&_h1]:font-semibold [&_h2]:mb-1 [&_h2]:font-semibold [&_h3]:mb-1 [&_h3]:font-semibold
                                    [&_table]:my-2 [&_table]:w-full [&_th]:border-b [&_th]:border-slate-200 [&_th]:px-2 [&_th]:py-1 [&_th]:text-left [&_td]:border-b [&_td]:border-slate-100 [&_td]:px-2 [&_td]:py-1 [&_code]:rounded [&_code]:bg-slate-100 [&_code]:px-1">
                            {!! \Illuminate\Support\Str::markdown($message['text'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
