<?php

namespace App\Http\Requests\Rules;

use App\Enums\RuleDirection;
use App\Enums\RuleMatchField;
use App\Enums\RuleOperator;
use App\Models\CategorizationRule;
use App\Models\Client;
use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Shared by create and update. Amounts arrive as dollars ("12.50") and are stored as cents.
 */
class CategorizationRuleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('client_id', $this->client()->id)->where('is_active', true),
            ],
            'match_field' => ['required', Rule::enum(RuleMatchField::class)],
            'operator' => ['required', Rule::enum(RuleOperator::class)],
            'pattern' => ['required', 'string', 'max:255', $this->validRegexWhenNeeded(...)],
            'direction' => ['required', Rule::enum(RuleDirection::class)],
            'amount_min' => ['nullable', 'string', $this->validAmount(...)],
            'amount_max' => ['nullable', 'string', $this->validAmount(...)],
            'priority' => ['required', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['boolean'],
            'apply_to_existing' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['account_id' => 'account', 'amount_min' => 'minimum amount', 'amount_max' => 'maximum amount'];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                ['amount_min_cents' => $min, 'amount_max_cents' => $max] = $this->ruleAttributes();
                if ($min !== null && $max !== null && $min > $max) {
                    $validator->errors()->add('amount_max', 'The maximum amount must be at least the minimum amount.');
                }
            },
        ];
    }

    /**
     * Validated attributes ready for the model.
     *
     * @return array<string, mixed>
     */
    public function ruleAttributes(): array
    {
        return [
            ...$this->safe()->except(['amount_min', 'amount_max', 'apply_to_existing']),
            'is_active' => $this->boolean('is_active'),
            'amount_min_cents' => $this->cents('amount_min'),
            'amount_max_cents' => $this->cents('amount_max'),
        ];
    }

    public function client(): Client
    {
        /** @var Client */
        return $this->route('client');
    }

    private function cents(string $key): ?int
    {
        $value = trim($this->string($key)->toString());

        return $value === '' ? null : abs(Money::toCents($value));
    }

    private function validAmount(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            Money::toCents((string) $value);
        } catch (InvalidArgumentException) {
            $fail('The :attribute must be an amount like 25 or 25.00.');
        }
    }

    private function validRegexWhenNeeded(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->input('operator') === RuleOperator::Regex->value && ! CategorizationRule::isValidRegex((string) $value)) {
            $fail('That pattern isn\'t a valid regular expression.');
        }
    }
}
