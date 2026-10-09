<?php

namespace App\Services;

use App\Mcp\InvalidArguments;
use App\Mcp\McpSettings;
use App\Mcp\Tool;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AjvaAI: a retail-analytics advisor on Google Gemini, used ONLY as a planner.
 *
 * Privacy rule: no business data is ever sent to Gemini. It receives the
 * instructions, the tool definitions (names, descriptions, argument schemas),
 * the user's questions and its own earlier plans. It answers with which
 * read-only tools to run (or one clarifying question). The app runs those tools
 * locally, shows the results itself and adds rule-based observations
 * (App\Support\LocalAdvisor). Tool results never leave the server; Gemini is
 * told only that a call "was executed locally".
 */
class AjvaAssistant
{
    /** Tool calls executed for one question. */
    private const MAX_CALLS = 6;

    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function configured(): bool
    {
        return filled(config('services.gemini.key'));
    }

    /**
     * Plan and run one question: $contents is Gemini's native history ending with
     * the new user turn. Returns the extended history, the model's own text (a
     * clarifying question or a short note, never figures) and the local results.
     *
     * @param  array<int, array<string, mixed>>  $contents
     * @return array{contents: array<int, array<string, mixed>>, text: string, results: array<int, array{tool: string, args: array<string, mixed>, data: array<string, mixed>}>}
     *
     * @throws AssistantException
     */
    public function reply(array $contents): array
    {
        $tools = $this->tools();
        $model = $this->generate($contents, $tools);
        $contents[] = $model;

        $calls = array_slice(array_values(array_filter($model['parts'] ?? [], fn ($p) => isset($p['functionCall']))), 0, self::MAX_CALLS);
        $note = trim(collect($model['parts'] ?? [])->filter(fn ($p) => isset($p['text']) && empty($p['thought']))->pluck('text')->implode(''));

        if ($calls === []) {
            return [
                'contents' => $contents,
                'text' => $note !== '' ? $note : __('The AI returned an empty answer. Rephrase the question and try again.'),
                'results' => [],
            ];
        }

        $results = [];
        $stubs = [];
        foreach ($calls as $part) {
            $name = (string) ($part['functionCall']['name'] ?? '');
            $args = $part['functionCall']['args'] ?? [];
            $args = is_array($args) ? $args : [];

            $data = $this->runTool($tools[$name] ?? null, $args);
            $results[] = ['tool' => $name, 'args' => $args, 'data' => $data];

            // Gemini needs a response for every call, but it must not carry data.
            $stubs[] = ['functionResponse' => [
                'name' => $name,
                'response' => [
                    'status' => isset($data['error']) ? 'failed' : 'executed locally',
                    'note' => 'The result is shown to the user inside the app and is not shared with you.',
                ],
            ]];
        }
        $contents[] = ['role' => 'user', 'parts' => $stubs];

        return ['contents' => $contents, 'text' => $note, 'results' => $results];
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

    private function runTool(?Tool $tool, array $args): array
    {
        if ($tool === null) {
            return ['error' => __('Unknown or disabled tool.')];
        }

        try {
            return $tool->handle($this->resolveShops($args));
        } catch (InvalidArguments $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('AjvaAI tool failed', ['tool' => $tool->name(), 'exception' => $e]);

            return ['error' => __('Internal error while running the tool.')];
        }
    }

    /**
     * The model cannot see shop ids (that would be data), so it names shops and
     * the app maps the names to ids here, locally.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function resolveShops(array $args): array
    {
        $find = function (string $name): int {
            $name = trim($name);
            $id = DB::table('shops')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id')
                ?? DB::table('shops')->where('name', 'like', '%'.addcslashes($name, '%_\\').'%')->value('id');
            if ($id === null) {
                throw new InvalidArguments(__('Shop ":name" was not found.', ['name' => $name]));
            }

            return (int) $id;
        };

        if (isset($args['shop_name'])) {
            $args['shop_id'] = $find((string) $args['shop_name']);
        }
        if (isset($args['shop_names']) && is_array($args['shop_names'])) {
            $args['shop_ids'] = array_values(array_unique(array_map(fn ($n) => $find((string) $n), $args['shop_names'])));
        }
        unset($args['shop_name'], $args['shop_names']);

        return $args;
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
            'generationConfig' => ['temperature' => 0.2],
        ];
        if ($tools !== []) {
            $body['tools'] = [['functionDeclarations' => array_values(array_map(fn (Tool $t) => $this->declaration($t), $tools))]];
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')])
                ->timeout(60)->acceptJson()
                ->post(sprintf(self::ENDPOINT, config('services.gemini.model')), $body);
        } catch (ConnectionException $e) {
            throw new AssistantException(__('Could not reach the AI service. Check the internet connection of the server.'), $this->redact($e->getMessage()));
        }

        if ($response->failed()) {
            Log::warning('AjvaAI Gemini error', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            throw new AssistantException(match (true) {
                $response->status() === 429 => __('The free AI limit is reached for now. Wait a minute and try again.'),
                in_array($response->status(), [400, 401, 403], true) => __('The AI service refused the request. Check GEMINI_API_KEY and GEMINI_MODEL in .env.'),
                default => __('The AI service is not available right now. Try again later.'),
            }, $this->describe($response));
        }

        $content = $response->json('candidates.0.content');
        if (! is_array($content) || ($content['parts'] ?? []) === []) {
            // e.g. a safety block: finishReason / promptFeedback explain why.
            throw new AssistantException(__('The AI returned an empty answer. Rephrase the question and try again.'), $this->describe($response));
        }
        $content['role'] = 'model';

        return $content;
    }

    /** Full technical text of a Gemini response for the error modal (status + body, key removed). */
    private function describe(\Illuminate\Http\Client\Response $response): string
    {
        $body = $response->json() !== null
            ? json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $response->body();

        return $this->redact('HTTP '.$response->status().' · model '.config('services.gemini.model')."\n\n".$body);
    }

    private function redact(string $text): string
    {
        $key = (string) config('services.gemini.key');

        return $key !== '' ? str_replace($key, '[hidden]', $text) : $text;
    }

    /** MCP JSON schema -> the OpenAPI subset Gemini accepts, with shops addressed by name. */
    private function declaration(Tool $tool): array
    {
        $declaration = ['name' => $tool->name(), 'description' => $tool->description()];
        $schema = $this->cleanSchema($tool->schema());

        // You cannot see shop ids, so shops are addressed by name and resolved by the app.
        if (isset($schema['properties']['shop_id'])) {
            unset($schema['properties']['shop_id']);
            $schema['properties']['shop_name'] = ['type' => 'string', 'description' => 'Shop name as the user wrote it (the app maps it to the id). Omit for all shops.'];
        }
        if (isset($schema['properties']['shop_ids'])) {
            unset($schema['properties']['shop_ids']);
            $schema['properties']['shop_names'] = ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Shop names as the user wrote them (the app maps them to ids). Omit for all shops.'];
        }

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
You are AjvaAI, a senior retail analyst inside the Ajwa admin panel (a multi-shop retail chain with a central warehouse; data comes from 1C and the POS tills). Today is {$today}.

PRIVACY - YOU NEVER SEE DATA
- You only get these instructions, the tool definitions and the user's questions. The app runs the tools you choose on its own server and shows the results to the user; you will NOT receive them. Never state, guess or estimate any figure, name or id from the business data.
- You do not know shop names or ids: when the user names a shop, pass it as shop_name / shop_names exactly as written and the app resolves it.

YOUR JOB
- Turn the user's question into the right read-only tool calls (period, shops, filters). Call several tools in one go when the question needs it (for example a summary plus receipt_analytics). Use few, well-aimed calls.
- Scope: only this project's retail analytics (sales, receipts, discounts, refunds, baskets and item relations, stock and replenishment, prices and margins, shops and cashiers). For anything else say briefly that you only help with Ajwa retail analytics.
- If the request is ambiguous (period, shop, metric, what "big" means), ask ONE short clarifying question with options instead of calling tools. If a sensible default exists (today, all shops), use it.
- Alongside the calls write 1-3 short sentences with NO numbers: which tools you ran and why, what the user should look at in the results, and a general retail best practice that applies (for example how to judge discount depth or where to look for refund abuse). The app adds rule-based observations from the real numbers itself.

PROJECT FACTS (definitions, not data)
- Sale receipt = active and sell; refund = active and not sell; cancelled = not active. "Sales" means successful sale receipts.
- Price id {$costId} is the COST price; every other price id is a SELL price. Margin = (sell - cost) / sell.
- Stock comes from 1C (latest synced snapshot); the min/max rule drives replenishment.

STYLE
- Answer in the language of the user's question (Uzbek if they write Uzbek). Concise, Markdown allowed.
PROMPT;
    }
}
