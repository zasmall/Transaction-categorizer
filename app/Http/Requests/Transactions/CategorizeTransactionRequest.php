<?php

namespace App\Http\Requests\Transactions;

use App\Models\Account;
use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategorizeTransactionRequest extends FormRequest
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
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('client_id', $this->client()->id)->where('is_active', true),
            ],
        ];
    }

    public function client(): Client
    {
        /** @var Client */
        return $this->route('client');
    }

    public function account(): Account
    {
        return $this->client()->accounts()->findOrFail($this->integer('account_id'));
    }
}
