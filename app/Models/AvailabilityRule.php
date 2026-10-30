<?php

namespace App\Models;

use Database\Factories\AvailabilityRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

// weekday is not fillable: each row belongs to one fixed weekday, set by server code
#[Fillable(['is_open', 'opens_at', 'closes_at'])]
class AvailabilityRule extends Model
{
    /** @use HasFactory<AvailabilityRuleFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_open' => 'boolean',
        ];
    }

    /**
     * The whole week, Sunday (0) to Saturday (6): the saved rule of each day, or a closed day when none is saved. The
     * Schedule editor and the Settings page read the hours from here.
     *
     * @return Collection<int, AvailabilityRule>
     */
    public static function week(): Collection
    {
        $saved = static::query()->get()->keyBy('weekday');

        return collect(range(0, 6))->mapWithKeys(fn (int $weekday) => [$weekday => $saved->get($weekday) ?? tap(new static(['is_open' => false]), fn ($rule) => $rule->weekday = $weekday)]);
    }
}
