export type ImportStatus =
    | 'pending'
    | 'parsing'
    | 'normalizing'
    | 'persisting'
    | 'completed'
    | 'completed_with_errors'
    | 'failed';

export type ImportRowStatus =
    | 'pending'
    | 'normalized'
    | 'imported'
    | 'duplicate'
    | 'failed';

export type ClientSummary = {
    name: string;
    slug: string;
};

export type Import = {
    id: number;
    original_filename: string;
    bank_account?: string;
    profile?: string;
    status: ImportStatus;
    is_finished: boolean;
    total_rows: number;
    imported_rows: number;
    duplicate_rows: number;
    failed_rows: number;
    error: string | null;
    uploaded_by?: string | null;
    created_at: string;
    finished_at: string | null;
};

export type ImportRow = {
    id: number;
    row_number: number;
    status: ImportRowStatus;
    error: string | null;
    raw: Record<string, string>;
    posted_on: string | null;
    payee: string | null;
    description: string | null;
    amount: string | null;
    amount_cents: number | null;
};
