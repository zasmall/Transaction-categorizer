<?php

namespace App\Http\Requests\Exports;

use App\Enums\CategorizationStatus;
use App\Models\Client;
use App\Models\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The date range and account an export covers. Shared by the preview, the
 * download and "mark as exported" so all three always agree.
 */
class ExportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->client()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')->where('client_id', $this->client()->id)],
            'include_exported' => ['boolean'],
        ];
    }

    public function client(): Client
    {
        /** @var Client */
        return $this->route('client');
    }

    /**
     * Every transaction in range, whatever its status.
     *
     * @return Builder<Transaction>
     */
    public function inRange(): Builder
    {
        return Transaction::forClient($this->client())
            ->when($this->validated('from'), fn (Builder $query, string $from) => $query->whereDate('posted_on', '>=', $from))
            ->when($this->validated('to'), fn (Builder $query, string $to) => $query->whereDate('posted_on', '<=', $to))
            ->when($this->validated('bank_account_id'), fn (Builder $query, int|string $id) => $query->where('bank_account_id', $id));
    }

    /**
     * What an export includes: approved transactions, not yet exported unless asked.
     *
     * @return Builder<Transaction>
     */
    public function exportable(): Builder
    {
        return $this->inRange()
            ->where('categorization_status', CategorizationStatus::Approved)
            ->when(! $this->boolean('include_exported'), fn (Builder $query) => $query->whereNull('exported_at'));
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'from' => $this->validated('from'),
            'to' => $this->validated('to'),
            'bank_account_id' => $this->validated('bank_account_id') === null ? null : (int) $this->validated('bank_account_id'),
            'include_exported' => $this->boolean('include_exported'),
        ];
    }
}
