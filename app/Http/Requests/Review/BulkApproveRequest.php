<?php

namespace App\Http\Requests\Review;

use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BulkApproveRequest extends FormRequest
{
    public const MAX_PER_REQUEST = 200;

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
            'transaction_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_PER_REQUEST],
            'transaction_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function client(): Client
    {
        /** @var Client */
        return $this->route('client');
    }

    /**
     * @return list<int>
     */
    public function transactionIds(): array
    {
        return array_values(array_map(intval(...), $this->array('transaction_ids')));
    }
}
