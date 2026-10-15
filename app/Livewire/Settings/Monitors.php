<?php

namespace App\Livewire\Settings;

use App\Enums\MonitorType;
use App\Enums\UserRole;
use App\Models\Monitor;
use App\Models\Shop;
use App\Models\User;
use App\Monitor\Presets;
use App\Support\PublicUrl;
use App\Support\Warehouse;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Monitors'])]
class Monitors extends Component
{
    public bool $showModal = false;

    public ?int $monitorId = null;

    public string $name = '';

    /** Type of the monitor being edited (read-only: a new monitor is always a custom one, built in the constructor). */
    #[Locked]
    public string $type = 'custom';

    public string $description = '';

    /** @var array<int, int|string> Shops shown by the monitor; empty = all shops. */
    public array $shopIds = [];

    /** @var array<int, string> Roles allowed to open it (admins always can); empty roles and users = everyone who may open monitors. */
    public array $roles = [];

    /** @var array<int, int|string> */
    public array $userIds = [];

    public string $userSearch = '';

    public bool $enabled = true;

    public bool $showProfit = false;

    public bool $isTemplate = false;

    /** The starting layout of a NEW monitor: "preset:{key}" (built in, incl. blank) or "monitor:{id}" (a saved template). */
    public string $templateSource = 'preset:executive';

    /**
     * Runs on every request, including Livewire updates, which skip the route
     * middleware - so a demoted admin can't keep using an already-open page.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $monitor = Monitor::query()->findOrFail($id);

        $this->resetForm();
        $this->monitorId = $monitor->id;
        $this->name = $monitor->name;
        $this->type = $monitor->type->value;
        $this->description = (string) $monitor->description;
        $this->shopIds = $monitor->shops()->pluck('shops.id')->map(fn ($id) => (string) $id)->all();
        $this->roles = array_values(array_map('strval', $monitor->roles ?? []));
        $this->userIds = $monitor->users()->pluck('users.id')->map(fn ($id) => (string) $id)->all();
        $this->enabled = $monitor->enabled;
        $this->showProfit = $monitor->show_profit;
        $this->isTemplate = $monitor->is_template;
        $this->showModal = true;
    }

    public function save()
    {
        $isNew = $this->monitorId === null;

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'shopIds' => ['array'],
            'shopIds.*' => ['integer', 'exists:shops,id'],
            'roles' => ['array'],
            'roles.*' => [Rule::in([UserRole::Operator->value, UserRole::Monitor->value])],
            'userIds' => ['array'],
            'userIds.*' => ['integer', 'exists:users,id'],
            'enabled' => ['boolean'],
            'showProfit' => ['boolean'],
            'isTemplate' => ['boolean'],
            'templateSource' => [$isNew ? 'required' : 'nullable', 'string'],
        ]);

        $existing = $isNew ? null : Monitor::query()->findOrFail($this->monitorId);

        // Every NEW monitor is a custom one, seeded from the chosen template, so it always has the constructor.
        $start = null;
        if ($isNew) {
            $start = $this->resolveTemplate();
            if ($start === null) {
                return null;
            }
        }

        $custom = $isNew || $existing->type === MonitorType::Custom;
        $attributes = [
            'name' => $data['name'],
            'description' => trim((string) ($data['description'] ?? '')) ?: ($start['description'] ?? null),
            'enabled' => $data['enabled'],
            'show_profit' => $data['showProfit'],
            'roles' => array_values(array_unique($data['roles'] ?? [])) ?: null,
            // Only a custom monitor has a layout that can be reused as a template.
            'is_template' => $custom && $data['isTemplate'],
        ];

        $monitor = $isNew
            // An empty start would otherwise get huge rows (the screen is divided by the rows used), so a blank monitor starts at 12 rows.
            ? Monitor::query()->create($attributes + ['type' => MonitorType::Custom, 'layout' => $start['layout'], 'layout_rows' => $start['layout'] === [] ? 12 : null])
            : tap($existing)->update($attributes);

        $monitor->shops()->sync(array_values(array_unique(array_map('intval', $data['shopIds'] ?? []))));
        $monitor->users()->sync(array_values(array_unique(array_map('intval', $data['userIds'] ?? []))));
        // A change of access or shops changes what the cached screens may show.
        $monitor->increment('layout_version');

        $this->closeModal();

        if ($isNew) {
            return $this->redirectRoute('settings.monitors.builder', ['monitor' => $monitor->id]);
        }

        return null;
    }

    /**
     * Turns an old fixed monitor (executive / receipts / warehouse) into an editable custom one: the layout of the closest
     * preset replaces the fixed screen. Name, shops, access, enabled, show_profit and the public link are kept. Opens the constructor.
     */
    public function convert(int $id)
    {
        $monitor = Monitor::query()->findOrFail($id);
        $preset = match ($monitor->type) {
            MonitorType::Executive => 'executive',
            MonitorType::Receipts => 'receipts',
            MonitorType::Warehouse => 'warehouse',
            default => null,
        };
        abort_if($preset === null, 422);

        $monitor->forceFill([
            'type' => MonitorType::Custom,
            'layout' => Presets::layout($preset),
            'layout_rows' => null,
            'layout_version' => $monitor->layout_version + 1,
        ])->save();

        return $this->redirectRoute('settings.monitors.builder', ['monitor' => $monitor->id]);
    }

    public function delete(int $id): void
    {
        Monitor::query()->findOrFail($id)->delete();
    }

    /** First or new UUID link; the previous one stops working immediately. Templates are never published. */
    public function generateLink(int $id): void
    {
        $monitor = Monitor::query()->findOrFail($id);
        abort_if($monitor->is_template, 422);

        $monitor->regenerateToken();
    }

    public function selectAllShops(): void
    {
        $this->shopIds = Shop::query()->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function clearShops(): void
    {
        $this->shopIds = [];
    }

    public function removeUser(int $id): void
    {
        $this->userIds = array_values(array_filter($this->userIds, fn ($u) => (int) $u !== $id));
    }

    public function addUser(int $id): void
    {
        if (User::query()->whereKey($id)->exists() && ! in_array((string) $id, array_map('strval', $this->userIds), true)) {
            $this->userIds[] = (string) $id;
        }
        $this->userSearch = '';
    }

    /** A copy of a monitor: same layout, shops, access; new widget ids; no public link (publish it separately). */
    public function duplicate(int $id): void
    {
        $source = Monitor::query()->findOrFail($id);

        $copy = Monitor::query()->create([
            'name' => mb_substr($source->name.' ('.__('copy').')', 0, 100),
            'type' => $source->type,
            'description' => $source->description,
            'enabled' => $source->enabled,
            'show_profit' => $source->show_profit,
            'is_template' => $source->is_template,
            'roles' => $source->roles,
            'layout' => $source->layout === null ? null : Presets::withFreshIds($source->widgets()),
            'layout_rows' => $source->layout_rows,
        ]);
        $copy->shops()->sync($source->shops()->pluck('shops.id')->all());
        $copy->users()->sync($source->users()->pluck('users.id')->all());
    }

    /**
     * The layout a new monitor starts with: a built-in preset or a saved template (never an ordinary monitor).
     *
     * @return array{layout: array<int, array<string, mixed>>, description: ?string}|null null = invalid choice (error added)
     */
    private function resolveTemplate(): ?array
    {
        [$kind, $ref] = array_pad(explode(':', $this->templateSource, 2), 2, '');

        if ($kind === 'preset' && Presets::exists($ref)) {
            return ['layout' => Presets::layout($ref), 'description' => Presets::description($ref)];
        }

        if ($kind === 'monitor' && ctype_digit($ref) && ($template = Monitor::query()->where('is_template', true)->find((int) $ref)) !== null) {
            return ['layout' => Presets::withFreshIds($template->widgets()), 'description' => $template->description];
        }

        $this->addError('templateSource', __('Choose a template.'));

        return null;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => __('Name'),
            'shopIds' => __('Shops'),
            'templateSource' => __('Template'),
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['monitorId', 'name', 'type', 'description', 'shopIds', 'roles', 'userIds', 'userSearch', 'enabled', 'showProfit', 'isTemplate', 'templateSource']);
        $this->resetErrorBag();
    }

    public function render()
    {
        $search = trim($this->userSearch);
        $selected = array_map('intval', $this->userIds);

        return view('livewire.settings.monitors', [
            'monitors' => Monitor::query()->with(['shops:id,name', 'users:id,name'])->orderBy('is_template')->orderBy('name')->orderBy('id')->get(),
            'shops' => Shop::query()->orderBy('name')->get(['id', 'name']),
            'warehouse' => Warehouse::shop(),
            'roleOptions' => [UserRole::Operator, UserRole::Monitor],
            'selectedUsers' => $selected === [] ? collect() : User::query()->whereIn('id', $selected)->orderBy('name')->get(['id', 'name', 'email', 'is_active']),
            'userMatches' => $search === '' ? collect() : User::query()
                ->where('role', '!=', UserRole::Admin->value)
                ->where('is_active', true)
                ->whereNotIn('id', $selected)
                ->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'))
                ->orderBy('name')->limit(8)->get(['id', 'name', 'email']),
            'presets' => Presets::all(),
            'savedTemplates' => Monitor::query()->where('is_template', true)->orderBy('name')->get(['id', 'name']),
            // Built from APP_URL, not from the host of the current request, so the
            // links are the same address the TV should use (e.g. behind a proxy).
            'appUrl' => PublicUrl::base(),
            'appUrlLooksLocal' => PublicUrl::looksLocal(),
        ]);
    }
}
