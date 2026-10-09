<?php

namespace App\Livewire\AjvaAI;

use App\Services\AjvaAssistant;
use App\Services\AssistantException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'AjvaAI'])]
class Index extends Component
{
    /** Raw Gemini history is trimmed to roughly this many entries (on a turn boundary). */
    private const MAX_CONTENTS = 40;

    public string $question = '';

    /** @var array<int, array<string, mixed>> Gemini-native history; locked so the client cannot forge tool results. */
    #[Locked]
    public array $contents = [];

    /** @var array<int, array{role: string, text: string}> What the page shows. */
    #[Locked]
    public array $messages = [];

    public ?string $error = null;

    public function boot(): void
    {
        // Defense in depth on top of the role:admin route group (the tools can read cost prices).
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function ask(AjvaAssistant $assistant): void
    {
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

        try {
            $result = $assistant->reply($contents);
        } catch (AssistantException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->contents = $this->trim($result['contents']);
        $this->messages[] = ['role' => 'model', 'text' => $result['text']];
    }

    public function useSuggestion(int $index): void
    {
        $this->question = $this->suggestions()[$index] ?? '';
    }

    public function newChat(): void
    {
        $this->contents = [];
        $this->messages = [];
        $this->error = null;
        $this->question = '';
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
        ]);
    }
}
