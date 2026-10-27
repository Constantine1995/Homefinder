<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MortgageProgramFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A mortgage program of the developer or a partner bank; the agent computes payments by these terms only.
 */
#[Fillable(['name', 'bank', 'rate', 'min_down_pct', 'max_amount', 'max_years', 'conditions', 'valid_to'])]
class MortgageProgram extends Model
{
    /** @use HasFactory<MortgageProgramFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'min_down_pct' => 'decimal:2',
            'valid_to' => 'date',
        ];
    }
}
