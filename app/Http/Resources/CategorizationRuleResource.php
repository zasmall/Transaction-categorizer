<?php

namespace App\Http\Resources;

use App\Models\CategorizationRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CategorizationRule
 */
class CategorizationRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'priority' => $this->priority,
            'match_field' => $this->match_field,
            'operator' => $this->operator,
            'pattern' => $this->pattern,
            'direction' => $this->direction,
            // Plain decimal strings for the form inputs.
            'amount_min' => $this->amount_min_cents === null ? null : number_format($this->amount_min_cents / 100, 2, '.', ''),
            'amount_max' => $this->amount_max_cents === null ? null : number_format($this->amount_max_cents / 100, 2, '.', ''),
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => "{$this->account->code} · {$this->account->name}"),
            'source' => $this->source,
            'hits_count' => $this->hits_count,
            'last_matched_at' => $this->last_matched_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'summary' => $this->describe(),
        ];
    }
}
