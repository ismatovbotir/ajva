<?php

namespace App\Mcp;

/**
 * A read-only MCP tool. Implementations must never write to the database.
 */
interface Tool
{
    public function name(): string;

    public function description(): string;

    /**
     * JSON Schema (object) describing the tool's arguments.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws InvalidArguments for bad input; the message is shown to the client
     */
    public function handle(array $arguments): array;
}
