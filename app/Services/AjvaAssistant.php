<?php

namespace App\Services;

use App\Mcp\InvalidArguments;
use App\Mcp\McpSettings;
use App\Mcp\Tool;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AjvaAI: a retail-analytics advisor on Google Gemini. The model never sees the
 * database directly; it can only call this project's read-only MCP tools
 * (config('mcp.tools'), respecting the on/off switches of /settings/mcp) via
 * Gemini function calling, so every figure it quotes comes from this app.
 */
class AjvaAssistant
{
    /** Tool round-trips allowed for one question. */
    private const MAX_STEPS = 6;

    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function configured(): bool
    {
        return filled(config('services.gemini.key'));
    }

    /**
     * Continue the conversation: $contents is Gemini's native history (ending
     * with the new user turn). Returns the extended history plus the answer.
     *
     * @param  array<int, array<string, mixed>>  $contents
     * @return array{contents: array<int, array<string, mixed>>, text: string}
     *
     * @throws AssistantException
     */
    public function reply(array $contents): array
    {
        $tools = $this->tools();

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $model = $this->generate($contents, $tools);
            $contents[] = $model;

            $calls = array_values(array_filter($model['parts'] ?? [], fn ($p) => isset($p['functionCall'])));
            if ($calls === []) {
                return ['contents' => $contents, 'text' => $this->text($model)];
            }

            $responses = [];
            foreach ($calls as $part) {
                $name = (string) ($part['functionCall']['name'] ?? '');
                $responses[] = ['functionResponse' => [
                    'name' => $name,
                    'response' => ['result' => $this->runTool($tools[$name] ?? null, $part['functionCall']['args'] ?? [])],
                ]];
            }
            $contents[] = ['role' => 'user', 'parts' => $responses];
        }

        // Out of steps: force a text answer from what was gathered.
        $contents[] = ['role' => 'user', 'parts' => [['text' => 'Answer now with what you already have; say what is missing.']]];
        $model = $this->generate($contents, []);
        $contents[] = $model;

        return ['contents' => $contents, 'text' => $this->text($model)];
    }

    /** @return array<string, Tool> */
    private function tools(): array
    {
        $settings = app(McpSettings::class);
        $tools = [];
        foreach (config('mcp.tools', []) as $class) {
            /** @var Tool $tool */
            $tool = app($class);
            if ($settings->toolEnabled($tool->name())) {
                $tools[$tool->name()] = $tool;
            }
        }

        return $tools;
    }

    private function runTool(?Tool $tool, mixed $args): array
    {
        if ($tool === null) {
            return ['error' => 'Unknown or disabled tool.'];
        }

        try {
            return $tool->handle(is_array($args) ? $args : []);
        } catch (InvalidArguments $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('AjvaAI tool failed', ['tool' => $tool->name(), 'exception' => $e]);

            return ['error' => 'Internal error while running the tool.'];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @param  array<string, Tool>  $tools
     * @return array<string, mixed> the model's content ({role, parts})
     */
    private function generate(array $contents, array $tools): array
    {
        $body = [
            'systemInstruction' => ['parts' => [['text' => $this->systemPrompt()]]],
            'contents' => $contents,
            'generationConfig' => ['temperature' => 0.3],
        ];
        if ($tools !== []) {
            $body['tools'] = [['functionDeclarations' => array_values(array_map(fn (Tool $t) => $this->declaration($t), $tools))]];
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')])
                ->timeout(60)->acceptJson()
                ->post(sprintf(self::ENDPOINT, config('services.gemini.model')), $body);
        } catch (ConnectionException) {
            throw new AssistantException(__('Could not reach the AI service. Check the internet connection of the server.'));
        }

        if ($response->failed()) {
            Log::warning('AjvaAI Gemini error', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            throw new AssistantException(match (true) {
                $response->status() === 429 => __('The free AI limit is reached for now. Wait a minute and try again.'),
                in_array($response->status(), [400, 401, 403], true) => __('The AI service refused the request. Check GEMINI_API_KEY and GEMINI_MODEL in .env.'),
                default => __('The AI service is not available right now. Try again later.'),
            });
        }

        $content = $response->json('candidates.0.content');
        if (! is_array($content) || ($content['parts'] ?? []) === []) {
            throw new AssistantException(__('The AI returned an empty answer. Rephrase the question and try again.'));
        }
        $content['role'] = 'model';

        return $content;
    }

    /** Text parts only (thought parts are never shown). */
    private function text(array $model): string
    {
        $text = collect($model['parts'] ?? [])
            ->filter(fn ($p) => isset($p['text']) && empty($p['thought']))
            ->pluck('text')->implode('');

        return trim($text) !== '' ? trim($text) : __('The AI returned an empty answer. Rephrase the question and try again.');
    }

    /** MCP JSON schema -> the OpenAPI subset Gemini accepts. */
    private function declaration(Tool $tool): array
    {
        $declaration = ['name' => $tool->name(), 'description' => $tool->description()];
        $schema = $this->cleanSchema($tool->schema());
        if (! empty($schema['properties'])) {
            $declaration['parameters'] = $schema;
        }

        return $declaration;
    }

    private function cleanSchema(mixed $schema): mixed
    {
        if (! is_array($schema)) {
            return $schema;
        }

        $out = [];
        foreach (['type', 'description', 'enum', 'required', 'minimum', 'maximum'] as $key) {
            if (isset($schema[$key])) {
                $out[$key] = $schema[$key];
            }
        }
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $out['properties'] = array_map(fn ($p) => $this->cleanSchema($p), $schema['properties']);
        }
        if (isset($schema['items'])) {
            $out['items'] = $this->cleanSchema($schema['items']);
        }

        return $out;
    }

    private function systemPrompt(): string
    {
        $costId = (int) config('inventory.cost_price_id');
        $today = now()->format('Y-m-d (l)');

        return <<<PROMPT
You are AjvaAI, a senior retail analyst and advisor inside the Ajwa admin panel (a multi-shop retail chain with a central warehouse; data comes from 1C and the POS tills). Today is {$today}.

SCOPE
- Work ONLY with this project's data and retail topics: sales, receipts, discounts, refunds, baskets and item relations, stock and replenishment, prices and margins, shop and cashier performance. If asked about anything else, say briefly that you only help with the Ajwa retail analytics.
- All numbers must come from the tools. Never invent or estimate figures. If a tool returns nothing, say so. Show the period, shops and definitions you used.

HOW TO WORK
- Prefer advice: after the facts, say what they mean and what to do (specific, prioritised actions, with the expected effect and the risk). Distinguish observation from hypothesis.
- If the question is ambiguous (period, shop, metric, what "big" means), ask ONE short clarifying question with options instead of guessing. If a sensible default exists (today, all shops), use it and state it.
- Plan before calling tools: use list_shops first when a shop name is mentioned; use few, well-aimed tool calls.
- Mention data-quality limits (missing cost, small samples, stock only as last synced from 1C). Do not draw conclusions from tiny samples.

PROJECT FACTS
- Money is in the local currency, no decimals needed. Sale receipt = active and sell; refund = active and not sell; cancelled = not active. "Sales" means successful sale receipts.
- Price id {$costId} is the COST (purchase) price; every other price id is a SELL price. Margin = (sell - cost) / sell.
- Stock comes from 1C (latest synced snapshot per item and shop); the min/max rule drives replenishment.

STYLE
- Answer in the language of the user's question (Uzbek by default if they write Uzbek). Be concise: short paragraphs, bullets, and small tables where numbers compare. No filler. Use Markdown.
PROMPT;
    }
}
