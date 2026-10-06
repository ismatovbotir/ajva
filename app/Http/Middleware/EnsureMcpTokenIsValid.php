<?php

namespace App\Http\Middleware;

use App\Mcp\McpSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsureMcpTokenIsValid
{
    /**
     * The MCP endpoint exposes business data, so it has its own bearer token
     * (generated in the admin panel's MCP settings, or env MCP_API_TOKEN),
     * separate from the POS token. It stays closed while no token exists, and
     * can be switched off entirely from the admin panel.
     */
    public function handle(Request $request, Closure $next)
    {
        $settings = app(McpSettings::class);

        if (! $settings->enabled()) {
            throw new HttpException(403, 'The MCP server is disabled.');
        }

        if (! $settings->matchesToken((string) $request->bearerToken())) {
            throw new HttpException(401, 'Invalid or missing API token.');
        }

        return $next($request);
    }
}
