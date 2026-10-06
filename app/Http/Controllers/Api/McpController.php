<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mcp\InvalidArguments;
use App\Mcp\McpSettings;
use App\Mcp\Tool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Minimal Model Context Protocol server over HTTP (JSON-RPC 2.0, "Streamable
 * HTTP" without streaming). Supports initialize, ping, tools/list and
 * tools/call. Tools come from config('mcp.tools').
 */
class McpController extends Controller
{
    private const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __invoke(Request $request): JsonResponse|Response
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || $payload === []) {
            return $this->error(null, -32700, 'Parse error');
        }

        // A JSON-RPC batch is a list; a single request is an object.
        if (array_is_list($payload)) {
            $responses = array_values(array_filter(array_map(fn ($m) => $this->dispatch($m), $payload)));

            return $responses === [] ? response('', 202) : response()->json($responses);
        }

        $response = $this->dispatch($payload);

        // Notifications get no body.
        return $response === null ? response('', 202) : response()->json($response);
    }

    /**
     * @param  mixed  $message
     * @return array<string, mixed>|null
     */
    private function dispatch($message): ?array
    {
        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! isset($message['method'])) {
            return $this->errorBody($message['id'] ?? null, -32600, 'Invalid Request');
        }

        $id = $message['id'] ?? null;
        $isNotification = ! array_key_exists('id', $message);
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        $result = match ($message['method']) {
            'initialize' => $this->initialize($params),
            'ping' => new \stdClass,
            'tools/list' => ['tools' => array_map(fn (Tool $t) => [
                'name' => $t->name(),
                'description' => $t->description(),
                'inputSchema' => $t->schema(),
                'annotations' => ['readOnlyHint' => true],
            ], array_values($this->tools()))],
            'tools/call' => $this->callTool($params),
            default => null,
        };

        if ($isNotification) {
            return null; // e.g. notifications/initialized
        }

        if ($result === null) {
            return $this->errorBody($id, -32601, 'Method not found');
        }

        if ($result instanceof \Closure) {
            return $this->errorBody($id, ...$result());
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::SUPPORTED_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => config('app.name', 'Ajwa').' MCP', 'version' => '1.0.0'],
            'instructions' => 'Read-only access to shop sales, stock and receipts. Start with list_shops, then sales_summary / hourly_sales / top_items / stock_levels. Dates are YYYY-MM-DD and default to today.',
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|\Closure
     */
    private function callTool(array $params): array|\Closure
    {
        $tool = $this->tools()[$params['name'] ?? ''] ?? null;

        if (! $tool) {
            return fn () => [-32602, 'Unknown tool: '.($params['name'] ?? '')];
        }

        $arguments = $params['arguments'] ?? [];
        if (! is_array($arguments)) {
            return fn () => [-32602, 'arguments must be an object'];
        }

        try {
            $data = $tool->handle($arguments);
            $isError = false;
        } catch (InvalidArguments $e) {
            $data = ['error' => $e->getMessage()];
            $isError = true;
        } catch (\Throwable $e) {
            Log::error('MCP tool failed', ['tool' => $tool->name(), 'exception' => $e]);
            $data = ['error' => 'Internal error while running the tool.'];
            $isError = true;
        }

        return [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]],
            'isError' => $isError,
        ];
    }

    /**
     * Tools from config('mcp.tools') that are switched on in the admin panel.
     *
     * @return array<string, Tool>
     */
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

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json($this->errorBody($id, $code, $message));
    }

    /**
     * @return array<string, mixed>
     */
    private function errorBody(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
