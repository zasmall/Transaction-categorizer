<?php

namespace App\Exports;

use App\Models\Transaction;

/**
 * Writes approved transactions as balanced journal entries in the column layout
 * QuickBooks Online's journal entry import expects: one entry per transaction,
 * two lines each (the bank or card account and the categorized account).
 *
 * Money out debits the category and credits the bank account; money in does the
 * reverse. Account names use each account's QuickBooks name when one is set.
 */
class QuickBooksJournalCsv
{
    public const HEADERS = ['Journal No', 'Journal Date', 'Account Name', 'Debits', 'Credits', 'Description', 'Memo'];

    /**
     * @param  iterable<Transaction>  $transactions  With bankAccount.ledgerAccount and account loaded.
     * @param  resource  $stream
     */
    public function write(iterable $transactions, $stream): int
    {
        fputcsv($stream, self::HEADERS, escape: '');
        $count = 0;

        foreach ($transactions as $transaction) {
            foreach ($this->lines($transaction) as $line) {
                fputcsv($stream, $line, escape: '');
            }
            $count++;
        }

        return $count;
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    public function lines(Transaction $transaction): array
    {
        $amount = number_format(abs($transaction->amount_cents) / 100, 2, '.', '');
        $bank = $transaction->bankAccount->ledgerAccount->exportName();
        $category = $transaction->account?->exportName() ?? '';
        $moneyOut = $transaction->amount_cents < 0;

        $common = [
            'TC-'.$transaction->id,
            $transaction->posted_on->format('m/d/Y'),
        ];
        $description = $transaction->payee_normalized;
        $memo = $transaction->memo ?? $transaction->description_raw;

        return [
            [...$common, $moneyOut ? $category : $bank, $amount, '', $description, $memo],
            [...$common, $moneyOut ? $bank : $category, '', $amount, $description, $memo],
        ];
    }
}
