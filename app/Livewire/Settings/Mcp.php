<?php

namespace App\Livewire\Settings;

use App\Mcp\McpSettings;
use App\Mcp\Tool;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'MCP server'])]
class Mcp extends Component
{
    /** The just-generated token; shown once, never stored in plaintext. */
    public ?string $newToken = null;

    /**
     * Runs on every request, including Livewire updates, which skip the route
     * middleware - so a demoted admin can't keep using an already-open page.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function toggleEnabled(McpSettings $settings): void
    {
        $settings->setEnabled(! $settings->enabled());
    }

    public function generateToken(McpSettings $settings): void
    {
        $this->newToken = $settings->generateToken();
    }

    public function toggleTool(McpSettings $settings, string $name): void
    {
        // Only names that exist in config can be toggled.
        if (collect($this->tools())->contains(fn (Tool $t) => $t->name() === $name)) {
            $settings->setToolEnabled($name, ! $settings->toolEnabled($name));
        }
    }

    public function render(McpSettings $settings)
    {
        return view('livewire.settings.mcp', [
            'enabled' => $settings->enabled(),
            'hasStoredToken' => $settings->hasStoredToken(),
            'hasEnvToken' => $settings->hasEnvToken(),
            'tokenCreatedAt' => $settings->tokenCreatedAt(),
            'endpoint' => url('/api/mcp'),
            'tools' => collect($this->tools())->map(fn (Tool $t) => [
                'name' => $t->name(),
                'description' => $t->description(),
                'enabled' => $settings->toolEnabled($t->name()),
            ])->all(),
        ]);
    }

    /**
     * @return array<int, Tool>
     */
    private function tools(): array
    {
        return array_map(fn ($class) => app($class), config('mcp.tools', []));
    }
}
