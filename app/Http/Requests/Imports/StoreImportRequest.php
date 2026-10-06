<?php

namespace App\Http\Requests\Imports;

use App\Models\BankAccount;
use App\Models\Client;
use App\Services\ImportService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreImportRequest extends FormRequest
{
    public const MAX_FILE_KILOBYTES = 5 * 1024;

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
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')
                    ->where('client_id', $this->client()->id)
                    ->whereNotNull('import_profile_id'),
            ],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.self::MAX_FILE_KILOBYTES],
            'allow_duplicate_file' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bank_account_id.exists' => 'Choose one of this client\'s bank accounts that has an import format set.',
        ];
    }

    /**
     * Warn before re-importing a file that was already imported into the same account.
     * Dedupe would skip every row anyway, so this is about catching a likely mistake.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->boolean('allow_duplicate_file')) {
                    return;
                }

                $previous = app(ImportService::class)->previousImportOf($this->bankAccount(), $this->statement());

                if ($previous !== null) {
                    $validator->errors()->add('file', sprintf(
                        'This exact file was already imported into this account on %s. Tick "Import anyway" to import it again.',
                        $previous->created_at?->toFormattedDayDateString(),
                    ));
                }
            },
        ];
    }

    public function client(): Client
    {
        /** @var Client */
        return $this->route('client');
    }

    public function bankAccount(): BankAccount
    {
        return $this->client()->bankAccounts()->findOrFail($this->integer('bank_account_id'));
    }

    public function statement(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->file('file');
    }
}
