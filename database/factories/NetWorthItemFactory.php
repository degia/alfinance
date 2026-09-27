<?php

namespace Database\Factories;

use App\Enums\NetWorthItemType;
use App\Enums\NetWorthSubtype;
use App\Models\NetWorthItem;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetWorthItem>
 */
class NetWorthItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'type' => NetWorthItemType::Asset,
            'subtype' => NetWorthSubtype::OtherAsset,
            'name' => $this->faker->randomElement(['Mobil', 'Emas', 'Saham', 'Rumah', 'Asuransi']),
            'value' => $this->faker->randomElement(['15000000.00', '25000000.00', '7500000.00']),
            'annual_rate' => null,
            'note' => null,
            'valued_at' => now()->toDateString(),
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
        ]);
    }

    public function asset(NetWorthSubtype $subtype = NetWorthSubtype::OtherAsset): static
    {
        return $this->state(fn (): array => [
            'type' => NetWorthItemType::Asset,
            'subtype' => $subtype,
        ]);
    }

    public function liability(NetWorthSubtype $subtype = NetWorthSubtype::OtherLiability): static
    {
        return $this->state(fn (): array => [
            'type' => NetWorthItemType::Liability,
            'subtype' => $subtype,
        ]);
    }

    public function value(string $amount): static
    {
        return $this->state(fn (): array => [
            'value' => $amount,
        ]);
    }

    /**
     * Aset ber-rate (mis. emas/investasi) supaya `valueAt()` ikut
     * dikompounding.
     */
    public function compounding(string $rate, ?string $value = null): static
    {
        return $this->state(fn (): array => array_filter([
            'annual_rate' => $rate,
            'value' => $value,
        ], fn (mixed $item): bool => $item !== null));
    }

    public function valuedAt(string $date): static
    {
        return $this->state(fn (): array => [
            'valued_at' => $date,
        ]);
    }
}
