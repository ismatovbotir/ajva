<?php

namespace App\Monitor;

/**
 * One entry of the widget catalog: everything the builder, the validator and the renderer need to know
 * about a metric. Immutable; built only in WidgetCatalog.
 *
 * shape    single | series | by_shop | by_item | parts | events (see Diagram::forShape())
 * dataset  the shared dataset the widget reads (MonitorData computes each needed dataset once per refresh)
 * cost     cheap | medium | expensive: the extra work the widget costs on top of its shared dataset
 */
final class Widget
{
    public const COSTS = ['cheap', 'medium', 'expensive'];

    /**
     * @param  array<int, Diagram>  $diagrams
     * @param  array<int, string>  $options  supported option names: top_n, metric
     * @param  array<string, string>  $sparkDatasets  extra dataset needed for a given diagram (sparkline needs the daily series)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $group,
        public readonly string $description,
        public readonly string $shape,
        public readonly array $diagrams,
        public readonly Diagram $default,
        public readonly int $w,
        public readonly int $h,
        public readonly array $min,
        public readonly array $max,
        public readonly int $ttl,
        public readonly string $cost,
        public readonly string $dataset,
        public readonly bool $profit = false,
        public readonly bool $comparison = false,
        public readonly bool $publicSafe = true,
        public readonly array $options = [],
        public readonly array $sparkDatasets = [],
        public readonly ?string $rule = null,
    ) {}

    public function title(): string
    {
        return __($this->label);
    }

    public function groupLabel(): string
    {
        return __($this->group);
    }

    public function summary(): string
    {
        return __($this->description);
    }

    public function supports(Diagram|string $diagram): bool
    {
        $value = $diagram instanceof Diagram ? $diagram : Diagram::tryFrom($diagram);

        return $value !== null && in_array($value, $this->diagrams, true);
    }

    public function isInventory(): bool
    {
        return $this->dataset === 'inventory';
    }

    /** Datasets this widget needs when drawn as $diagram (the sparkline adds the daily series). */
    public function datasets(Diagram|string $diagram): array
    {
        $value = $diagram instanceof Diagram ? $diagram->value : $diagram;
        // Profit widgets also compare against the receipts baselines (the receipt counts gate the colours).
        $sets = $this->dataset === 'profit' ? ['profit', 'receipts'] : [$this->dataset];
        if (isset($this->sparkDatasets[$value])) {
            $sets[] = $this->sparkDatasets[$value];
        }

        return $sets;
    }

    /** @return array<string, mixed> what the builder JS/Blade needs, nothing else */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->title(),
            'group' => $this->groupLabel(),
            'description' => $this->summary(),
            'diagrams' => array_map(fn (Diagram $d) => ['value' => $d->value, 'label' => $d->label()], $this->diagrams),
            'default' => $this->default->value,
            'w' => $this->w,
            'h' => $this->h,
            'cost' => $this->cost,
            'profit' => $this->profit,
            'publicSafe' => $this->publicSafe,
            'comparison' => $this->comparison,
            'options' => $this->options,
        ];
    }
}
