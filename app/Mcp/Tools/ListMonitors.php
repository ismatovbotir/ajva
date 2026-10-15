<?php

namespace App\Mcp\Tools;

use App\Enums\MonitorType;
use App\Mcp\BaseTool;
use App\Models\Monitor;

class ListMonitors extends BaseTool
{
    public function name(): string
    {
        return 'list_monitors';
    }

    public function description(): string
    {
        return 'The wall / TV monitors configured in the admin panel: id, name, type (executive, receipts, warehouse, custom), enabled, which shops they show (empty list = all shops), whether profit is shown, whether it is a reusable template, whether a public link exists, and for custom monitors the number of widgets and their keys. Links and tokens are never returned.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => array_map(fn (MonitorType $t) => $t->value, MonitorType::cases()), 'description' => 'Only monitors of this type.'],
                'include_templates' => ['type' => 'boolean', 'description' => 'Also list reusable layout templates (default true).'],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $type = $this->optionalEnum($arguments, 'type', array_map(fn (MonitorType $t) => $t->value, MonitorType::cases()));

        // The token column is deliberately not selected.
        $monitors = Monitor::query()
            ->select(['id', 'name', 'type', 'enabled', 'show_profit', 'is_template', 'description', 'layout', 'roles'])
            ->selectRaw('(token IS NOT NULL) AS has_link')
            ->with('shops:id,name')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when(($arguments['include_templates'] ?? true) === false, fn ($q) => $q->screens())
            ->orderBy('name')->orderBy('id')
            ->get();

        return [
            'monitors' => $monitors->map(function (Monitor $m) {
                $widgets = $m->widgets();

                return [
                    'id' => $m->id,
                    'name' => $m->name,
                    'type' => $m->type->value,
                    'enabled' => $m->enabled,
                    'is_template' => $m->is_template,
                    'description' => $m->description,
                    'all_shops' => $m->shops->isEmpty(),
                    'shops' => $m->shops->pluck('name')->sort()->values()->all(),
                    'shows_profit' => $m->show_profit,
                    'has_public_link' => (bool) $m->getAttribute('has_link'),
                    'roles' => is_array($m->roles) ? array_values($m->roles) : [],
                    'widget_count' => $m->type === MonitorType::Custom ? count($widgets) : null,
                    'widget_keys' => $m->type === MonitorType::Custom ? array_values(array_unique(array_column($widgets, 'key'))) : null,
                ];
            })->all(),
        ];
    }
}
