<?php

namespace App\Livewire\AjvaAI;

use App\Jobs\RunAjvaQuestion;
use App\Services\AjvaAssistant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'AjvaAI'])]
class Index extends Component
{
    /** Raw Gemini history is trimmed to roughly this many entries (on a turn boundary). */
    private const MAX_CONTENTS = 40;

    /** A run that has not reported back after this long is declared lost (no worker running?). */
    private const RUN_GIVE_UP_SECONDS = 240;

    public string $question = '';

    /** @var array<int, array<string, mixed>> Gemini-native history; locked so the client cannot forge tool results. */
    #[Locked]
    public array $contents = [];

    /** @var array<int, array<string, mixed>> What the page shows (questions, plan notes, local results). */
    #[Locked]
    public array $messages = [];

    /** Id of the question being answered in the background (empty = idle). */
    #[Locked]
    public string $runId = '';

    #[Locked]
    public int $runStartedAt = 0;

    public ?string $error = null;

    public function boot(): void
    {
        // Defense in depth on top of the role:admin route group (the tools can read cost prices).
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /**
     * Start answering. The slow part (the AI call and the tools) runs in a queued
     * job, so this request returns at once and the page polls checkRun(); no web
     * or PHP timeout can cut a long answer. With QUEUE_CONNECTION=sync the job
     * simply runs inside this request (with PHP's time limit lifted).
     */
    public function ask(AjvaAssistant $assistant): void
    {
        if ($this->runId !== '') {
            return; // one question at a time
        }

        $this->validate(['question' => ['required', 'string', 'max:1500']]);
        $this->error = null;

        $question = trim($this->question);
        $this->messages[] = ['role' => 'user', 'text' => $question];
        $this->question = '';

        if (! $assistant->configured()) {
            $this->error = __('AjvaAI is not configured: set GEMINI_API_KEY in .env.');

            return;
        }

        $contents = $this->contents;
        $contents[] = ['role' => 'user', 'parts' => [['text' => $question]]];

        $this->runId = Str::random(24);
        $this->runStartedAt = now()->timestamp;
        $userId = (int) auth()->id();

        Cache::put(RunAjvaQuestion::key($userId, $this->runId), ['status' => 'pending'], now()->addMinutes(15));
        RunAjvaQuestion::dispatch($userId, $this->runId, $contents);

        $this->checkRun(); // the sync queue has already finished; a real queue answers on the next poll
    }

    /** Polled by the page while a question is being answered. */
    public function checkRun(): void
    {
        if ($this->runId === '') {
            return;
        }

        $state = Cache::get(RunAjvaQuestion::key((int) auth()->id(), $this->runId)) ?? ['status' => 'pending'];

        if ($state['status'] === 'done') {
            $this->contents = $this->trim($state['contents']);
            $this->messages[] = $state['message'];
        } elseif ($state['status'] === 'failed') {
            $this->error = $state['error'];
        } elseif (now()->timestamp - $this->runStartedAt > self::RUN_GIVE_UP_SECONDS) {
            $this->error = __('No answer arrived in time. Check that the queue worker is running (php artisan queue:work), then try again.');
        } else {
            return; // still working
        }

        Cache::forget(RunAjvaQuestion::key((int) auth()->id(), $this->runId));
        $this->runId = '';
    }

    public function useSuggestion(int $index): void
    {
        $this->question = $this->suggestions()[$index] ?? '';
    }

    public function newChat(): void
    {
        if ($this->runId !== '') {
            Cache::forget(RunAjvaQuestion::key((int) auth()->id(), $this->runId));
        }

        $this->contents = [];
        $this->messages = [];
        $this->error = null;
        $this->question = '';
        $this->runId = '';
    }

    /** @return array<int, string> */
    private function suggestions(): array
    {
        return [
            __('How were sales yesterday compared with the day before, and what should I do about it?'),
            __('Which shops give the biggest discounts this week, and is it worth it?'),
            __('What should we replenish first from the warehouse?'),
        ];
    }

    /** Drop the oldest turns, always restarting at a real user question (never mid tool-call). */
    private function trim(array $contents): array
    {
        while (count($contents) > self::MAX_CONTENTS) {
            array_shift($contents);
            while ($contents !== [] && ! (($contents[0]['role'] ?? '') === 'user' && isset($contents[0]['parts'][0]['text']))) {
                array_shift($contents);
            }
        }

        return $contents;
    }

    public function render()
    {
        return view('livewire.ajva-ai.index', [
            'configured' => app(AjvaAssistant::class)->configured(),
            'suggestions' => $this->suggestions(),
            'working' => $this->runId !== '',
        ]);
    }
}
