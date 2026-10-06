<?php

use App\Imports\Normalizing\PayeeNormalizer;

test('it cleans bank descriptions into payee names', function (string $description, string $payee) {
    expect((new PayeeNormalizer)->normalize($description))->toBe($payee);
})->with([
    'square prefix, store number, state' => ['SQ *BLUE BOTTLE COFFEE #0423 OAKLAND CA', 'Blue Bottle Coffee Oakland'],
    'toast prefix' => ['TST* THE GRILL ROOM NEW YORK NY', 'The Grill Room New York'],
    'debit card authorization' => ['PURCHASE AUTHORIZED ON 01/07 STAPLES 00123 BERKELEY CA', 'Staples Berkeley'],
    'pos with masked card' => ['POS PURCHASE SHELL OIL 57444 XXXX1234', 'Shell Oil'],
    'ach addenda' => ['STRIPE TRANSFER ST-X7K2M9 PPD ID: 1800948598', 'Stripe Transfer'],
    'ach prefix with trailing id' => ['ACH DEBIT GUSTO PAYROLL 123456', 'Gusto Payroll'],
    'phone number' => ['ADOBE *CREATIVE CLD 800-833-6687 CA', 'Adobe Creative Cld'],
    'reference after asterisk' => ['AMAZON MKTPL*2K3LD0BX2', 'Amazon Mktpl'],
    'short trip id' => ['UBER   *TRIP 8H3KD', 'Uber Trip'],
    'plain text untouched' => ['MONTHLY SERVICE FEE', 'Monthly Service Fee'],
    'payment text keeps its words' => ['ONLINE PAYMENT - THANK YOU', 'Online Payment - Thank You'],
    'names with digits survive' => ['7-ELEVEN 35021 SAN JOSE CA', '7-Eleven San Jose'],
    'short alphanumeric names survive' => ['3M COMPANY', '3M Company'],
]);

test('it never returns an empty payee', function () {
    expect((new PayeeNormalizer)->normalize('#1234'))->toBe('#1234');
});
