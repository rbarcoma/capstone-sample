export type Dataset = {
    id: number;
    user_id?: number;
    user?: {
        id: number;
        name: string;
    } | null;
    type: string;
    original_name: string;
    status: string;
    record_count: number;
    validation_errors?: string[];
    created_at: string;
};

export type ProcessedRecord = {
    id: number;
    year: number;
    month: number;
    consumption_kwh: number;
    temperature: number;
    humidity: number;
    rainfall: number;
    solar_irradiance: number;
    peak_demand_kw: number;
};

export type ForecastResult = {
    id: number;
    user_id?: number;
    user?: {
        id: number;
        name: string;
    } | null;
    predicted_at?: string;
    issuance_label?: string;
    metric_definitions?: Record<string, string>;
    artifact_id?: string | null;
    dataset_hash?: string | null;
    training_cutoff?: string | null;
    evaluation_protocol?: string | null;
    artifact_provenance?: {
        status: 'recorded' | 'unavailable' | 'incomplete';
        artifact_id: string | null;
        dataset_hash: string | null;
        training_cutoff: string | null;
        evaluation_protocol: string | null;
    };
    year: number;
    month: number;
    predicted_consumption_kwh: number | null;
    previous_consumption_kwh?: number | null;
    change_percent?: number | null;
    mae?: number | null;
    rmse?: number | null;
    r2_score?: number | null;
    model_type?: string;
    created_at: string;
};

export type DssResult = {
    id: number;
    user_id?: number;
    forecast_result_id?: number;
    user?: {
        id: number;
        name: string;
    } | null;
    forecast_result?: {
        id: number;
        year: number;
        month: number;
        issuance_label?: string;
    } | null;
    demand_status: string;
    readiness_level: string;
    recommendations: string[];
    priority_actions: string[];
    basis?: Record<string, unknown> | null;
    created_at: string;
    updated_at?: string;
};

export type Report = {
    id: number;
    user_id?: number;
    user?: {
        id: number;
        name: string;
    } | null;
    title: string;
    type: string;
    summary: Record<string, unknown>;
    created_at: string;
};
