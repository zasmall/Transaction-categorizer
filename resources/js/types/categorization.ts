export type CategorizationStatus = 'uncategorized' | 'suggested' | 'approved';

export type AccountOption = {
    id: number;
    code: string;
    name: string;
    type: string;
};

export type Option = {
    value: string;
    label: string;
};

export type TransactionRow = {
    id: number;
    posted_on: string;
    payee: string;
    description: string;
    amount: string;
    amount_cents: number;
    bank_account: string;
    account_id: number | null;
    status: CategorizationStatus;
    explanation: string | null;
    ai_reason: string | null;
};

export type RuleDirection = 'any' | 'inflow' | 'outflow';

export type CategorizationRule = {
    id: number;
    name: string;
    priority: number;
    match_field: string;
    operator: string;
    pattern: string;
    direction: RuleDirection;
    amount_min: string | null;
    amount_max: string | null;
    account_id: number;
    account?: string;
    source: 'manual' | 'learned';
    hits_count: number;
    last_matched_at: string | null;
    is_active: boolean;
    summary: string;
};

/** Prefilled values for the rule form; also what the edit page receives. */
export type RuleFormValues = Pick<
    CategorizationRule,
    | 'name'
    | 'priority'
    | 'match_field'
    | 'operator'
    | 'pattern'
    | 'direction'
    | 'amount_min'
    | 'amount_max'
    | 'is_active'
> & { account_id: number | null };

export type RuleSuggestion = {
    payee: string;
    account_id: number;
    account: string;
    similar: number;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
