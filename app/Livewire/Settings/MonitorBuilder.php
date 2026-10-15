<?php

namespace App\Livewire\Settings;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Monitor\Diagram;
use App\Monitor\Grid;
use App\Monitor\LayoutValidator;
use App\Monitor\MonitorData;
use App\Monitor\WidgetCatalog;
use App\Support\MonitorScope;
use App\Support\ProfitAccess;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Admin-only layout editor ("constructor") of a custom monitor: a 12-column canvas drawn as a 16:9 TV frame,
 * a widget palette and a side panel.
 *
 * The canvas renders the REAL x-monitor.* widgets fed with generated SAMPLE data (App\Monitor\SampleDatasets): no
 * query, no ForecastService, no cost data, so drag and resize are instant and a monitor without data still looks alive.
 * Real numbers are one click away in Preview mode (as the signed-in admin or as the public link).
 *
 * The working layout is a #[Locked] property: the browser can never write it, it can only call the actions
 * below with ids and numbers, and every action clamps its input to the catalog limits and re-resolves
 * collisions through App\Monitor\Grid. save() validates the whole layout again (LayoutValidator) before
 * anything is stored, bumps layout_version (which makes every cached dataset of the old layout unreachable).
 */
#[Layout('components.layouts.app', ['title' => 'Monitor builder'])]
class MonitorBuilder extends Component
{
    public const HISTORY = 20;

    #[Locked]
    public int $monitorId;

    #[Locked]
    public string $monitorName = '';

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $widgets = [];

    /** @var array<int, array<int, array<string, mixed>>> previous layouts, newest last */
    #[Locked]
    public array $history = [];

    /** @var array<int, array<int, array<string, mixed>>> undone layouts (redo), newest last */
    #[Locked]
    public array $future = [];

    public ?string $selectedId = null;

    public string $search = '';

    public bool $preview = false;

    /** 'me' = as the signed-in admin sees it, 'public' = as the public link would. */
    public string $previewAs = 'me';

    /** Row density: 0 = as many rows as the layout uses, else a fixed row count (Monitor::ROW_CHOICES). */
    public int $rowsSetting = 0;

    /** Canvas zoom: fit (to the width of the page) or a fixed factor; only affects the editor, not the monitor. */
    public string $zoom = 'fit';

    public bool $dirty = false;

    /** @var array<int, string> */
    public array $notices = [];

    // Side panel fields of the selected widget (applied by applyPanel(), always re-validated).
    public string $panelDiagram = '';

    public string $panelTitle = '';

    public int $panelTopN = WidgetCatalog::TOP_N_DEFAULT;

    public string $panelMetric = 'sum';

    public int $panelX = 0;

    public int $panelY = 0;

    public int $panelW = 1;

    public int $panelH = 1;

    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function mount(Monitor $monitor): void
    {
        abort_unless($monitor->type === MonitorType::Custom, 404);

        $this->monitorId = $monitor->id;
        $this->monitorName = $monitor->name;
        $this->rowsSetting = in_array((int) $monitor->layout_rows, Monitor::ROW_CHOICES, true) ? (int) $monitor->layout_rows : 0;
        $this->widgets = Grid::resolve($monitor->widgets());
    }

    private function monitor(): Monitor
    {
        return Monitor::query()->findOrFail($this->monitorId);
    }

    // ------------------------------------------------------------------ selection and palette

    public function select(?string $id): void
    {
        $widget = $id === null ? null : $this->find($id);
        $this->selectedId = $widget['id'] ?? null;

        if ($widget === null) {
            return;
        }

        $this->panelDiagram = $widget['diagram'];
        $this->panelTitle = (string) ($widget['options']['title'] ?? '');
        $this->panelTopN = (int) ($widget['options']['top_n'] ?? WidgetCatalog::TOP_N_DEFAULT);
        $this->panelMetric = (string) ($widget['options']['metric'] ?? 'sum');
        [$this->panelX, $this->panelY, $this->panelW, $this->panelH] = [$widget['x'], $widget['y'], $widget['w'], $widget['h']];
    }

    public function addWidget(string $key): void
    {
        $def = WidgetCatalog::find($key);
        if ($def === null) {
            return;
        }

        $candidate = Grid::add($this->widgets, $def);
        if (! $this->accept($candidate)) {
            return;
        }

        $newId = collect($candidate)->pluck('id')->diff(collect($this->widgets)->pluck('id'))->first();
        $this->commit($candidate);
        $this->select($newId);
    }

    /** Drop from the palette: the widget lands at the dropped cell (then the grid pushes the others down). */
    public function addWidgetAt(string $key, int $x, int $y): void
    {
        $def = WidgetCatalog::find($key);
        if ($def === null) {
            return;
        }

        $candidate = Grid::add($this->widgets, $def);
        $newId = collect($candidate)->pluck('id')->diff(collect($this->widgets)->pluck('id'))->first();
        $added = collect($candidate)->firstWhere('id', $newId);
        $candidate = Grid::place($candidate, $newId, $x, $y, $added['w'], $added['h']);

        if (! $this->accept($candidate)) {
            return;
        }

        $this->commit($candidate);
        $this->select($newId);
    }

    // ------------------------------------------------------------------ geometry

    public function move(string $id, int $x, int $y): void
    {
        if ($this->find($id) === null) {
            return;
        }

        $this->commit(Grid::move($this->widgets, $id, $x, $y));
        $this->select($id);
    }

    public function resize(string $id, int $w, int $h): void
    {
        if ($this->find($id) === null) {
            return;
        }

        $this->commit(Grid::resize($this->widgets, $id, $w, $h));
        $this->select($id);
    }

    /** Resize from any edge or corner: position and size in one step (the size is clamped to the catalog). */
    public function place(string $id, int $x, int $y, int $w, int $h): void
    {
        if ($this->find($id) === null) {
            return;
        }

        $this->commit(Grid::place($this->widgets, $id, $x, $y, $w, $h));
        $this->select($id);
    }

    /** Keyboard: arrow keys move by one cell, with the size flag they resize. */
    public function nudge(string $id, int $dx, int $dy, bool $size = false): void
    {
        $widget = $this->find($id);
        if ($widget === null) {
            return;
        }

        $dx = max(-1, min(1, $dx));
        $dy = max(-1, min(1, $dy));

        $size
            ? $this->resize($id, $widget['w'] + $dx, $widget['h'] + $dy)
            : $this->move($id, $widget['x'] + $dx, $widget['y'] + $dy);
    }

    // ------------------------------------------------------------------ side panel

    /** Applies the side panel fields (diagram, title, options and the numeric x/y/w/h) to the selected widget. */
    public function applyPanel(): void
    {
        $widget = $this->selectedId === null ? null : $this->find($this->selectedId);
        $def = $widget === null ? null : WidgetCatalog::find($widget['key']);
        if ($widget === null || $def === null) {
            return;
        }

        $candidate = $this->widgets;
        foreach ($candidate as &$w) {
            if ($w['id'] !== $widget['id']) {
                continue;
            }
            if ($def->supports($this->panelDiagram)) {
                $w['diagram'] = $this->panelDiagram;
            }
            $options = $w['options'] ?? [];
            unset($options['title']);
            if (trim($this->panelTitle) !== '') {
                $options['title'] = Str::limit(trim(strip_tags($this->panelTitle)), 60, '');
            }
            if (in_array('top_n', $def->options, true)) {
                $options['top_n'] = max(1, min(WidgetCatalog::TOP_N_MAX, $this->panelTopN));
            }
            if (in_array('metric', $def->options, true) && in_array($this->panelMetric, ['sum', 'count'], true)) {
                $options['metric'] = $this->panelMetric;
            }
            $w['options'] = $options;
            [$w['x'], $w['y'], $w['w'], $w['h']] = [$this->panelX, $this->panelY, $this->panelW, $this->panelH];
        }
        unset($w);

        $candidate = Grid::resolve($candidate, $widget['id']);
        if ($this->accept($candidate)) {
            $this->commit($candidate);
        }
        $this->select($widget['id']);
    }

    // ------------------------------------------------------------------ edit operations

    public function remove(string $id): void
    {
        if ($this->find($id) === null) {
            return;
        }

        $this->commit(Grid::resolve(array_values(array_filter($this->widgets, fn ($w) => $w['id'] !== $id))));
        $this->selectedId = null;
    }

    public function duplicate(string $id): void
    {
        $widget = $this->find($id);
        if ($widget === null) {
            return;
        }

        $copy = $widget;
        $copy['id'] = (string) Str::uuid();
        $copy['y'] = collect($this->widgets)->max(fn ($w) => $w['y'] + $w['h']);

        $candidate = Grid::resolve([...$this->widgets, $copy]);
        if ($this->accept($candidate)) {
            $this->commit($candidate);
            $this->select($copy['id']);
        }
    }

    public function undo(): void
    {
        if ($this->history === []) {
            return;
        }

        $this->future[] = $this->widgets;
        $this->widgets = array_pop($this->history);
        $this->dirty = true;
        $this->notices = [];
        $this->select($this->selectedId);
    }

    public function redo(): void
    {
        if ($this->future === []) {
            return;
        }

        $this->history[] = $this->widgets;
        $this->widgets = array_pop($this->future);
        $this->dirty = true;
        $this->notices = [];
        $this->select($this->selectedId);
    }

    /** Row density select: only the known choices, anything else means "as many rows as the layout uses". */
    public function updatedRowsSetting(): void
    {
        if (! in_array($this->rowsSetting, Monitor::ROW_CHOICES, true)) {
            $this->rowsSetting = 0;
        }
        $this->dirty = true;
    }

    public function updatedZoom(): void
    {
        if (! in_array($this->zoom, ['fit', '1', '0.75'], true)) {
            $this->zoom = 'fit';
        }
    }

    public function togglePreview(): void
    {
        $this->preview = ! $this->preview;
    }

    /** Validates the whole layout against the catalog and stores it; the client never supplies the layout. */
    public function save(): void
    {
        $result = LayoutValidator::validate($this->widgets);

        if ($result['errors'] !== []) {
            $this->notices = $result['errors'];

            return;
        }

        $monitor = $this->monitor();
        $monitor->forceFill([
            'layout' => $result['layout'],
            'layout_rows' => $this->rowsSetting ?: null,
            'layout_version' => $monitor->layout_version + 1,
        ])->save();

        $this->widgets = $result['layout'];
        $this->history = [];
        $this->future = [];
        $this->dirty = false;
        $this->notices = [];
        $this->dispatch('monitor-layout-saved');
        session()->flash('status', __('Layout saved.'));
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed>|null */
    private function find(string $id): ?array
    {
        foreach ($this->widgets as $widget) {
            if ($widget['id'] === $id) {
                return $widget;
            }
        }

        return null;
    }

    /** Refuses a change that would break a limit (and says why). */
    private function accept(array $candidate): bool
    {
        $errors = LayoutValidator::limits($candidate);
        $this->notices = $errors;

        return $errors === [];
    }

    private function commit(array $layout): void
    {
        // A drag or key press that changes nothing (e.g. a size already at its limit) is not an undo step.
        if ($layout === $this->widgets) {
            return;
        }

        $this->history[] = $this->widgets;
        $this->history = array_slice($this->history, -self::HISTORY);
        $this->widgets = $layout;
        $this->future = [];
        $this->dirty = true;
        $this->notices = [];
    }

    public function render()
    {
        $selected = $this->selectedId === null ? null : $this->find($this->selectedId);
        $selectedDef = $selected === null ? null : WidgetCatalog::find($selected['key']);

        $search = mb_strtolower(trim($this->search));
        $groups = [];
        foreach (WidgetCatalog::grouped() as $group => $defs) {
            $matches = array_values(array_filter($defs, fn ($d) => $search === ''
                || str_contains(mb_strtolower($d->title()), $search)
                || str_contains(mb_strtolower($d->summary()), $search)
                || str_contains(mb_strtolower($d->key), $search)));
            if ($matches !== []) {
                $groups[$group] = $matches;
            }
        }

        // Edit canvas: generated sample data, no database. Preview: real data of the monitor's shops (as me / as the public link).
        if ($this->preview) {
            $public = $this->previewAs === 'public';
            $monitor = $this->monitor();
            $scope = new MonitorScope(
                monitor: $monitor,
                shopIds: $monitor->shopIds(),
                withProfit: $public ? $monitor->show_profit : ProfitAccess::allowed(),
                public: $public,
                now: now(),
            );
            $screen = MonitorData::build($scope, $this->widgets);
        } else {
            // Nothing is hidden on the canvas (the numbers are invented); what a viewer really sees is checked in Preview.
            $scope = new MonitorScope(new Monitor, null, true, false, now());
            $screen = MonitorData::build($scope, $this->widgets, true);
        }

        $expensive = count(array_filter($this->widgets, fn ($w) => (WidgetCatalog::find($w['key'])?->cost) === 'expensive'));
        $heatmaps = count(array_filter($this->widgets, fn ($w) => $w['diagram'] === Diagram::Heatmap->value));

        return view('livewire.settings.monitor-builder', [
            'groups' => $groups,
            'selected' => $selected,
            'selectedDef' => $selectedDef,
            'screen' => $screen,
            'horizon' => max(1, min(7, (int) config('inventory.order_horizon_days', 3))),
            'rows' => max($this->rowsSetting, (int) collect($this->widgets)->max(fn ($w) => $w['y'] + $w['h']), 1),
            'rowChoices' => Monitor::ROW_CHOICES,
            'limits' => [
                'widgets' => [count($this->widgets), WidgetCatalog::MAX_WIDGETS],
                'expensive' => [$expensive, WidgetCatalog::MAX_EXPENSIVE],
                'heatmaps' => [$heatmaps, WidgetCatalog::MAX_HEATMAPS],
            ],
            'problems' => LayoutValidator::limits($this->widgets),
        ]);
    }
}
