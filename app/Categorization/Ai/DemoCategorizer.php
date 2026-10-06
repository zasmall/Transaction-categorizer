<?php

namespace App\Categorization\Ai;

use App\Models\Account;
use App\Models\Client;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Stand-in for the AI when no API key is configured, so the suggest-and-review flow
 * can be demonstrated. It matches keywords to account names in the client's chart;
 * every suggestion it makes says it came from demo mode.
 */
class DemoCategorizer implements AiCategorizer
{
    public const MODEL = 'demo-keyword-matcher';

    /**
     * Keywords => a word that should appear in the target account's name.
     *
     * @var array<string, string>
     */
    private const KEYWORDS = [
        'coffee|cafe|café|espresso|restaurant|grill|diner|pizza|bakery|lunch|doordash|grubhub' => 'Meals',
        'adobe|zoom|slack|google|microsoft|dropbox|notion|github|aws|software|staples|office' => 'Software',
        'uber|lyft|taxi|air lines|airlines|delta|united|hotel|marriott|hilton|airbnb' => 'Travel',
        'service fee|monthly fee|overdraft|wire fee|bank fee' => 'Bank Fees',
        'pg&e|electric|water|gas co|comcast|verizon|at&t|internet' => 'Utilities',
        'shell|chevron|exxon|parking|toll' => 'Vehicle',
        'insurance|geico|state farm' => 'Insurance',
        'rent|lease|wework' => 'Rent',
        'facebook|meta ads|google ads|mailchimp' => 'Advertising',
        'stripe|square deposit|transfer from' => 'Revenue',
    ];

    public function suggest(Client $client, Collection $transactions, Collection $accounts, array $examples): AiSuggestionBatch
    {
        $suggestions = [];

        foreach ($transactions as $transaction) {
            $suggestion = $this->fromExamples($transaction, $examples)
                ?? $this->fromKeywords($transaction, $accounts);

            if ($suggestion !== null) {
                $suggestions[] = $suggestion;
            }
        }

        return new AiSuggestionBatch($suggestions, self::MODEL);
    }

    /**
     * @param  list<array{payee: string, account_code: string}>  $examples
     */
    private function fromExamples(Transaction $transaction, array $examples): ?AiSuggestion
    {
        foreach ($examples as $example) {
            if (strcasecmp($example['payee'], $transaction->payee_normalized) === 0) {
                return new AiSuggestion(
                    $transaction->id,
                    $example['account_code'],
                    85,
                    'Demo mode: same payee was approved to this account before.',
                );
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function fromKeywords(Transaction $transaction, Collection $accounts): ?AiSuggestion
    {
        $text = mb_strtolower($transaction->payee_normalized.' '.$transaction->description_raw);

        foreach (self::KEYWORDS as $pattern => $accountWord) {
            if (! preg_match('/'.str_replace(['/', '.'], ['\/', '\.'], $pattern).'/u', $text, $match)) {
                continue;
            }

            $account = $accounts->first(fn (Account $account) => str_contains($account->name, $accountWord));
            if ($account !== null) {
                return new AiSuggestion(
                    $transaction->id,
                    $account->code,
                    60,
                    "Demo mode: matched the keyword \"{$match[0]}\".",
                );
            }
        }

        return null;
    }
}
