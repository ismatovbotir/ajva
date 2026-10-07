<?php

namespace Database\Factories;

use App\Enums\MonitorType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Monitor>
 */
class MonitorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'type' => MonitorType::Executive,
            'token' => null,
            'enabled' => true,
            'show_profit' => false,
        ];
    }

    public function type(MonitorType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function withLink(): static
    {
        return $this->state(fn () => ['token' => (string) Str::uuid()]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }

    public function withProfit(): static
    {
        return $this->state(fn () => ['show_profit' => true]);
    }
}
