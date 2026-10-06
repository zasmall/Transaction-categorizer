<?php

use App\Categorization\RuleEngine;
use App\Enums\RuleDirection;
use App\Enums\RuleMatchField;
use App\Enums\RuleOperator;
use App\Models\CategorizationRule;
use App\Models\Transaction;

/**
 * @param  array<string, mixed>  $attributes
 */
function rule(array $attributes = []): CategorizationRule
{
    $rule = new CategorizationRule([
        'name' => 'Test rule',
        'priority' => 100,
        'match_field' => RuleMatchField::Payee,
        'operator' => RuleOperator::Contains,
        'pattern' => 'coffee',
        'direction' => RuleDirection::Any,
        ...$attributes,
    ]);
    $rule->id = $attributes['id'] ?? 1;

    return $rule;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function transaction(array $attributes = []): Transaction
{
    return new Transaction([
        'amount_cents' => -575,
        'payee_normalized' => 'Blue Bottle Coffee Oakland',
        'description_raw' => 'SQ *BLUE BOTTLE COFFEE #0423 OAKLAND CA',
        'memo' => null,
        ...$attributes,
    ]);
}

test('text operators match case-insensitively', function (RuleOperator $operator, string $pattern, bool $expected) {
    expect(rule(['operator' => $operator, 'pattern' => $pattern])->matches(transaction()))->toBe($expected);
})->with([
    'contains' => [RuleOperator::Contains, 'BOTTLE coffee', true],
    'contains miss' => [RuleOperator::Contains, 'peets', false],
    'starts with' => [RuleOperator::StartsWith, 'blue bottle', true],
    'starts with miss' => [RuleOperator::StartsWith, 'coffee', false],
    'equals' => [RuleOperator::Equals, ' blue bottle coffee oakland ', true],
    'equals miss' => [RuleOperator::Equals, 'blue bottle coffee', false],
    'regex' => [RuleOperator::Regex, '^blue\s+bottle', true],
    'regex miss' => [RuleOperator::Regex, '^coffee', false],
    'regex with tilde' => [RuleOperator::Regex, 'a~b', false],
]);

test('an invalid regex never matches', function () {
    expect(CategorizationRule::isValidRegex('(unclosed'))->toBeFalse()
        ->and(CategorizationRule::isValidRegex('^blue|green$'))->toBeTrue()
        ->and(rule(['operator' => RuleOperator::Regex, 'pattern' => '(unclosed'])->matches(transaction()))->toBeFalse();
});

test('rules can match the raw description or the memo', function () {
    expect(rule(['match_field' => RuleMatchField::Description, 'pattern' => 'sq *'])->matches(transaction()))->toBeTrue()
        ->and(rule(['match_field' => RuleMatchField::Memo, 'pattern' => 'client lunch'])->matches(transaction(['memo' => 'Client lunch'])))->toBeTrue()
        ->and(rule(['match_field' => RuleMatchField::Memo, 'pattern' => 'lunch'])->matches(transaction()))->toBeFalse();
});

test('direction limits rules to money in or money out', function () {
    $refund = transaction(['amount_cents' => 575]);

    expect(rule(['direction' => RuleDirection::Outflow])->matches(transaction()))->toBeTrue()
        ->and(rule(['direction' => RuleDirection::Outflow])->matches($refund))->toBeFalse()
        ->and(rule(['direction' => RuleDirection::Inflow])->matches($refund))->toBeTrue()
        ->and(rule(['direction' => RuleDirection::Any])->matches($refund))->toBeTrue();
});

test('amount bounds apply to the absolute amount and are inclusive', function (?int $min, ?int $max, bool $expected) {
    expect(rule(['amount_min_cents' => $min, 'amount_max_cents' => $max])->matches(transaction(['amount_cents' => -575])))->toBe($expected);
})->with([
    'within' => [500, 1000, true],
    'at the minimum' => [575, null, true],
    'at the maximum' => [null, 575, true],
    'below the minimum' => [600, null, false],
    'above the maximum' => [null, 500, false],
]);

test('the engine picks the highest priority match, then the oldest rule', function () {
    $engine = new RuleEngine(collect([
        rule(['id' => 3, 'priority' => 50, 'pattern' => 'coffee', 'name' => 'Newer, same priority']),
        rule(['id' => 1, 'priority' => 100, 'pattern' => 'coffee', 'name' => 'Low priority']),
        rule(['id' => 2, 'priority' => 50, 'pattern' => 'bottle', 'name' => 'Older, same priority']),
        rule(['id' => 4, 'priority' => 1, 'pattern' => 'tea', 'name' => 'No match']),
    ]));

    expect($engine->firstMatch(transaction())?->name)->toBe('Older, same priority')
        ->and($engine->firstMatch(transaction(['payee_normalized' => 'Hardware Store'])))->toBeNull();
});

test('rules describe themselves in plain English', function () {
    expect(rule()->describe())->toBe('Payee contains "coffee"')
        ->and(rule([
            'operator' => RuleOperator::StartsWith,
            'direction' => RuleDirection::Outflow,
            'amount_min_cents' => 1000,
            'amount_max_cents' => 5000,
        ])->describe())->toBe('Payee starts with "coffee", money out, $10.00–$50.00')
        ->and(rule(['match_field' => RuleMatchField::Description, 'amount_max_cents' => 2500])->describe())
        ->toBe('Bank description contains "coffee", up to $25.00');
});
