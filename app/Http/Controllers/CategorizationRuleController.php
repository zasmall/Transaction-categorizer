<?php

namespace App\Http\Controllers;

use App\Categorization\LearnedRuleSuggestions;
use App\Enums\RuleDirection;
use App\Enums\RuleMatchField;
use App\Enums\RuleOperator;
use App\Enums\RuleSource;
use App\Http\Requests\Rules\CategorizationRuleRequest;
use App\Http\Resources\CategorizationRuleResource;
use App\Models\CategorizationRule;
use App\Models\Client;
use App\Services\CategorizationService;
use App\Support\AccountOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CategorizationRuleController extends Controller
{
    public function index(Client $client, LearnedRuleSuggestions $learnedRules): Response
    {
        Gate::authorize('view', $client);

        return Inertia::render('rules/Index', [
            'client' => $client->only('name', 'slug'),
            'learnedRules' => $learnedRules->for($client),
            'minApprovals' => LearnedRuleSuggestions::MIN_APPROVALS,
            'rules' => CategorizationRuleResource::collection(
                $client->rules()->with('account')->orderBy('priority')->orderBy('id')->get(),
            )->resolve(),
        ]);
    }

    /**
     * Accepts ?payee=…&account_id=… so "create a rule from this transaction" can prefill the form.
     */
    public function create(Request $request, Client $client): Response
    {
        Gate::authorize('view', $client);

        $payee = $request->string('payee')->limit(255, '')->toString();

        return Inertia::render('rules/Create', [
            ...$this->formProps($client),
            'rule' => [
                'name' => $payee,
                'match_field' => RuleMatchField::Payee,
                'operator' => RuleOperator::Contains,
                'pattern' => $payee,
                'direction' => RuleDirection::Any,
                'amount_min' => null,
                'amount_max' => null,
                'account_id' => $request->integer('account_id') ?: null,
                'priority' => 100,
                'is_active' => true,
            ],
        ]);
    }

    public function store(CategorizationRuleRequest $request, Client $client, CategorizationService $categorizer): RedirectResponse
    {
        $client->rules()->create($request->ruleAttributes());

        $this->flashSaved($request, $client, $categorizer);

        return to_route('clients.rules.index', $client);
    }

    public function edit(Client $client, CategorizationRule $rule): Response
    {
        Gate::authorize('view', $client);

        return Inertia::render('rules/Edit', [
            ...$this->formProps($client),
            'rule' => (new CategorizationRuleResource($rule))->resolve(),
        ]);
    }

    public function update(CategorizationRuleRequest $request, Client $client, CategorizationRule $rule, CategorizationService $categorizer): RedirectResponse
    {
        $rule->update($request->ruleAttributes());

        $this->flashSaved($request, $client, $categorizer);

        return to_route('clients.rules.index', $client);
    }

    /**
     * Past categorizations keep the rule's name, so history still explains itself.
     */
    public function destroy(Client $client, CategorizationRule $rule): RedirectResponse
    {
        Gate::authorize('view', $client);

        $rule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule deleted.')]);

        return to_route('clients.rules.index', $client);
    }

    /**
     * Turn a learned-rule suggestion into a rule. The suggestion is looked up again
     * rather than trusting the request, so only genuine candidates become rules.
     */
    public function storeLearned(Request $request, Client $client, LearnedRuleSuggestions $learnedRules, CategorizationService $categorizer): RedirectResponse
    {
        Gate::authorize('view', $client);

        $validated = $request->validate([
            'payee' => ['required', 'string', 'max:255'],
            'account_id' => ['required', 'integer'],
        ]);

        $candidate = $learnedRules->for($client)->first(fn (array $candidate) => $candidate['account_id'] === (int) $validated['account_id']
            && mb_strtolower($candidate['payee']) === mb_strtolower($validated['payee']));

        abort_if($candidate === null, 422, 'That suggestion is no longer available.');

        $client->rules()->create([
            'name' => $candidate['payee'],
            'match_field' => RuleMatchField::Payee,
            'operator' => RuleOperator::Equals,
            'pattern' => $candidate['payee'],
            'direction' => RuleDirection::Any,
            'account_id' => $candidate['account_id'],
            'source' => RuleSource::Learned,
            'priority' => 100,
            'is_active' => true,
        ]);

        $count = $categorizer->applyRules($client, $client->transactions()->getQuery());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule created from :payee.', ['payee' => $candidate['payee']]).' '.trans_choice(
            '{0} No other transactions needed it yet.|{1} It categorized 1 more transaction.|[2,*] It categorized :count more transactions.',
            $count,
        )]);

        return back();
    }

    /**
     * Run every active rule over the client's uncategorized and suggested transactions.
     */
    public function apply(Client $client, CategorizationService $categorizer): RedirectResponse
    {
        Gate::authorize('view', $client);

        $count = $categorizer->applyRules($client, $client->transactions()->getQuery());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            '{0} No uncategorized transactions matched a rule.|{1} Rules categorized 1 transaction.|[2,*] Rules categorized :count transactions.',
            $count,
        )]);

        return back();
    }

    private function flashSaved(CategorizationRuleRequest $request, Client $client, CategorizationService $categorizer): void
    {
        $message = __('Rule saved.');

        if ($request->boolean('apply_to_existing')) {
            $count = $categorizer->applyRules($client, $client->transactions()->getQuery());
            $message .= ' '.trans_choice('{0} No existing transactions matched.|{1} Categorized 1 existing transaction.|[2,*] Categorized :count existing transactions.', $count);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(Client $client): array
    {
        return [
            'client' => $client->only('name', 'slug'),
            'accounts' => AccountOptions::for($client),
            'matchFields' => array_map(fn (RuleMatchField $field) => ['value' => $field->value, 'label' => $field->label()], RuleMatchField::cases()),
            'operators' => array_map(fn (RuleOperator $operator) => ['value' => $operator->value, 'label' => $operator->label()], RuleOperator::cases()),
        ];
    }
}
