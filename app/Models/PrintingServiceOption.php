<?php

namespace App\Models;

use App\Enums\OptionGroup;
use Database\Factories\PrintingServiceOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// printing_service_id is not fillable: the service always comes from the route, never from the request
#[Fillable(['group', 'label', 'price_modifier', 'is_active'])]
class PrintingServiceOption extends Model
{
    /** @use HasFactory<PrintingServiceOptionFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'group' => OptionGroup::class,
            'price_modifier' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<PrintingService, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(PrintingService::class, 'printing_service_id');
    }

    /** @param Builder<PrintingServiceOption> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
