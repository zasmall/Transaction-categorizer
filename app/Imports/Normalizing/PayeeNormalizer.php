<?php

namespace App\Imports\Normalizing;

use Illuminate\Support\Str;

/**
 * Turns noisy bank descriptions into stable payee names that rules can match,
 * e.g. "SQ *BLUE BOTTLE COFFEE #0423 OAKLAND CA" becomes "Blue Bottle Coffee Oakland".
 *
 * City names are left alone (there's no reliable way to tell them from a merchant
 * name), so rules should match payees with "contains" or "starts with".
 */
class PayeeNormalizer
{
    /** Card processor and point-of-sale prefixes. */
    private const PREFIXES = [
        '/^(?:POS|DEBIT CARD|CHECKCARD|CHECK CARD|DBT CRD|PURCHASE)\s+(?:PURCHASE\s+)?(?:AUTHORIZED ON\s+\d{1,2}\/\d{1,2}\s+)?/i',
        '/^(?:SQ|SQU|TST|SP|PP|PAYPAL|IN|PY|DD|FS|BT)\s?\*\s*/i',
        '/^ACH\s+(?:DEBIT|CREDIT)\s+/i',
    ];

    /** Noise that follows the merchant name. */
    private const NOISE = [
        '/\s+(?:PPD|CCD|WEB|TEL)\s+ID:.*$/i', // ACH addenda
        '/\s+\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\b/', // dates like 01/15 or 01/15/26
        '/\s*#\s*\d+/', // store numbers like #0423
        '/\s+(?:STORE|STR)\s*\d+\b/i',
        '/\s+\(?\d{3}\)?[-. ]\d{3}[-. ]\d{4}\b/', // phone numbers
        '/\s+X{2,}\d+\b/i', // masked card numbers like XXXX1234
        '/\s+(?=[A-Z0-9-]*\d[A-Z0-9-]*\d)[A-Z0-9-]{5,}\b/i', // reference ids: 5+ chars with 2+ digits
        '/\s+\d{3,}\b/', // bare store or terminal numbers
    ];

    private const US_STATES = 'AL|AK|AZ|AR|CA|CO|CT|DC|DE|FL|GA|HI|ID|IL|IN|IA|KS|KY|LA|ME|MD|MA|MI|MN|MS|MO|MT|NE|NV|NH|NJ|NM|NY|NC|ND|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VT|VA|WA|WV|WI|WY';

    public function normalize(string $description): string
    {
        $payee = trim((string) preg_replace('/\s+/', ' ', $description));

        foreach (self::PREFIXES as $pattern) {
            $payee = (string) preg_replace($pattern, '', $payee);
        }

        $payee = str_replace('*', ' ', $payee);

        foreach (self::NOISE as $pattern) {
            $payee = (string) preg_replace($pattern, '', $payee);
        }

        // A trailing state code, as long as at least two words come before it.
        $payee = (string) preg_replace('/^(\S+\s+\S+.*?)\s+(?:'.self::US_STATES.')$/i', '$1', trim($payee));

        $payee = trim((string) preg_replace('/\s+/', ' ', $payee), " \t-");

        // Fall back to the raw text rather than ever producing an empty payee.
        return $payee === '' ? Str::title(trim($description)) : Str::title($payee);
    }
}
