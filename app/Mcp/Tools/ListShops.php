<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use Illuminate\Support\Facades\DB;

class ListShops extends BaseTool
{
    public function name(): string
    {
        return 'list_shops';
    }

    public function description(): string
    {
        return 'List all shops with their ids. Use the ids to filter the other tools.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function handle(array $arguments): array
    {
        return [
            'shops' => DB::table('shops')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->all(),
        ];
    }
}
