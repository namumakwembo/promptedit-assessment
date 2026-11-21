export type GenerationSummary = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    status:
        | 'submitting'
        | 'submission_unknown'
        | 'queued'
        | 'processing'
        | 'succeeded'
        | 'failed';
    estimated_min_usd: string;
    estimated_max_usd: string;
    actual_cost_usd: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    created_at: string;
    error_message: string | null;
    can_retry: boolean;
};

export type GenerationPage = {
    data: GenerationSummary[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type GenerationEstimate = {
    quote_id: string;
    expires_at: string;
    minimum_usd: string;
    maximum_usd: string;
};
