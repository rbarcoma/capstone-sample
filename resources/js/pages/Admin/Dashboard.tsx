import { StatCard } from '@/components/dss/stat-card';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { displayValue } from '@/lib/display-value';
import { legacyDssNotice } from '@/lib/dss-evidence';
import type { Dataset, DssResult, ForecastResult, Report } from '@/types';
import {
    Activity,
    ArrowDownRight,
    ArrowUpRight,
    BarChart3,
    Brain,
    CheckCircle2,
    Database,
    FileText,
    Layers,
    LineChart,
    ServerCog,
    Sun,
    UploadCloud,
    Zap,
} from 'lucide-react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type AdminStats = {
    latest_average_consumption?: number | null;
    latest_predicted_consumption?: number | null;
    latest_period?: string;
    forecast_period?: string;
    forecast_issuance?: string;
    forecast_change_percent?: number | null;
    previous_consumption?: number | null;
    peak_demand_kw?: number | null;
    solar_irradiance?: number | null;
    demand_status?: string;
    readiness_level?: string;
    dss_evidence?: Pick<DssResult, 'basis'> | null;
    datasets?: number;
    processed_records?: number;
    forecasts?: number;
    reports?: number;
};

type TrendPoint = {
    year: number;
    month: number;
    consumption_kwh: number;
    peak_demand_kw?: number;
    solar_irradiance?: number;
};

type YearlyPoint = {
    year: number;
    consumption_kwh: number;
};

function formatNumber(value?: number | string | null, suffix = '') {
    if (displayValue(value) === 'Unavailable') {
        return 'Unavailable';
    }

    return `${Number(value).toLocaleString(undefined, {
        maximumFractionDigits: 2,
    })}${suffix}`;
}

function formatTooltipNumber(value: unknown, suffix = '') {
    const numberValue = Array.isArray(value) ? value[0] : value;

    return formatNumber(
        typeof numberValue === 'number' || typeof numberValue === 'string'
            ? numberValue
            : null,
        suffix,
    );
}

function formatPeriod(year?: number, month?: number) {
    if (!year || !month) {
        return '-';
    }

    return `${year}-${String(month).padStart(2, '0')}`;
}

function statusClass(status?: string) {
    const normalized = status?.toLowerCase() ?? '';

    if (normalized.includes('high')) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }

    if (normalized.includes('low')) {
        return 'border-red-200 bg-red-50 text-red-700';
    }

    return 'border-amber-200 bg-amber-50 text-amber-700';
}

function trendChartData(data: TrendPoint[]) {
    return data.map((item) => ({
        period: formatPeriod(item.year, item.month),
        consumption: Number(item.consumption_kwh ?? 0),
        peakDemand: Number(item.peak_demand_kw ?? 0),
        solar: Number(item.solar_irradiance ?? 0),
    }));
}

const tooltipStyle = {
    backgroundColor: 'var(--popover)',
    border: '1px solid var(--border)',
    borderRadius: '8px',
    color: 'var(--popover-foreground)',
};

function ConsumptionTrendChart({ data }: { data: TrendPoint[] }) {
    return (
        <div className="h-[320px] w-full">
            <ResponsiveContainer width="100%" height="100%">
                <AreaChart
                    data={trendChartData(data)}
                    margin={{ top: 12, right: 18, left: 4, bottom: 8 }}
                >
                    <defs>
                        <linearGradient
                            id="adminConsumption"
                            x1="0"
                            x2="0"
                            y1="0"
                            y2="1"
                        >
                            <stop
                                offset="5%"
                                stopColor="#059669"
                                stopOpacity={0.28}
                            />
                            <stop
                                offset="95%"
                                stopColor="#059669"
                                stopOpacity={0.02}
                            />
                        </linearGradient>
                    </defs>
                    <CartesianGrid
                        stroke="var(--border)"
                        strokeDasharray="3 3"
                    />
                    <XAxis
                        dataKey="period"
                        axisLine={{ stroke: 'var(--border)' }}
                        tick={{ fill: 'var(--muted-foreground)', fontSize: 12 }}
                        tickLine={false}
                        tickMargin={10}
                    />
                    <YAxis
                        axisLine={{ stroke: 'var(--border)' }}
                        tick={{ fill: 'var(--muted-foreground)', fontSize: 12 }}
                        tickFormatter={(value) => `${Number(value) / 1000}k`}
                        tickLine={false}
                    />
                    <Tooltip
                        formatter={(value) => [
                            formatTooltipNumber(value, ' kWh'),
                            'Consumption',
                        ]}
                        labelFormatter={(label) => `Period ${label}`}
                        contentStyle={tooltipStyle}
                    />
                    <Area
                        type="monotone"
                        dataKey="consumption"
                        stroke="#059669"
                        strokeWidth={3}
                        fill="url(#adminConsumption)"
                        dot={{ r: 3, strokeWidth: 2 }}
                        activeDot={{ r: 6 }}
                    />
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
}

function YearlyAverageChart({ data }: { data: YearlyPoint[] }) {
    const prepared = data.map((item) => ({
        year: String(item.year),
        consumption: Number(item.consumption_kwh ?? 0),
    }));

    return (
        <div className="h-[220px] w-full">
            <ResponsiveContainer width="100%" height="100%">
                <AreaChart
                    data={prepared}
                    margin={{ top: 8, right: 8, left: 0, bottom: 4 }}
                >
                    <defs>
                        <linearGradient
                            id="adminYearlyConsumption"
                            x1="0"
                            x2="0"
                            y1="0"
                            y2="1"
                        >
                            <stop
                                offset="5%"
                                stopColor="#059669"
                                stopOpacity={0.28}
                            />
                            <stop
                                offset="95%"
                                stopColor="#059669"
                                stopOpacity={0.02}
                            />
                        </linearGradient>
                    </defs>
                    <CartesianGrid
                        stroke="var(--border)"
                        strokeDasharray="3 3"
                    />
                    <XAxis
                        dataKey="year"
                        axisLine={{ stroke: 'var(--border)' }}
                        tick={{ fill: 'var(--muted-foreground)', fontSize: 12 }}
                        tickLine={false}
                    />
                    <YAxis
                        axisLine={{ stroke: 'var(--border)' }}
                        tick={{ fill: 'var(--muted-foreground)', fontSize: 12 }}
                        tickFormatter={(value) => `${Number(value) / 1000}k`}
                        tickLine={false}
                    />
                    <Tooltip
                        formatter={(value) => [
                            formatTooltipNumber(value, ' kWh'),
                            'Average',
                        ]}
                        contentStyle={tooltipStyle}
                    />
                    <Area
                        type="monotone"
                        dataKey="consumption"
                        stroke="#059669"
                        strokeWidth={3}
                        fill="url(#adminYearlyConsumption)"
                        dot={{ r: 3, strokeWidth: 2 }}
                        activeDot={{ r: 6 }}
                    />
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
}

function EnergyConditionChart({ data }: { data: TrendPoint[] }) {
    return (
        <div className="h-[220px] w-full">
            <ResponsiveContainer width="100%" height="100%">
                <BarChart
                    data={trendChartData(data).slice(-6)}
                    margin={{ top: 8, right: 8, left: 0, bottom: 4 }}
                >
                    <CartesianGrid
                        stroke="var(--border)"
                        strokeDasharray="3 3"
                    />
                    <XAxis
                        dataKey="period"
                        axisLine={{ stroke: 'var(--border)' }}
                        tick={{ fill: 'var(--muted-foreground)', fontSize: 12 }}
                        tickLine={false}
                    />
                    <YAxis
                        axisLine={false}
                        tick={{ fill: 'var(--muted-foreground)', fontSize: 12 }}
                        tickLine={false}
                    />
                    <Tooltip contentStyle={tooltipStyle} />
                    <Bar
                        dataKey="peakDemand"
                        name="Peak Demand"
                        fill="#059669"
                        radius={[4, 4, 0, 0]}
                    />
                    <Bar
                        dataKey="solar"
                        name="Solar Irradiance"
                        fill="#f59e0b"
                        radius={[4, 4, 0, 0]}
                    />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}

export default function Dashboard({
    stats,
    recentForecasts = [],
    recentReports = [],
    recentDatasets = [],
    trend = [],
    yearly = [],
}: {
    stats: AdminStats;
    recentForecasts: ForecastResult[];
    recentReports: Report[];
    recentDatasets: Dataset[];
    trend: TrendPoint[];
    yearly: YearlyPoint[];
}) {
    const forecastChange = Number(stats.forecast_change_percent ?? 0);
    const changeIsIncrease = forecastChange >= 0;
    const ChangeIcon = changeIsIncrease ? ArrowUpRight : ArrowDownRight;
    const changeDisplay = formatNumber(stats.forecast_change_percent, '%');

    return (
        <div className="space-y-6 p-6">
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Renewable Energy DSS Dashboard
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {legacyDssNotice(stats.dss_evidence)}
                        Quezon City electricity analytics, forecasting, and
                        transition readiness.
                    </p>
                </div>

                <div className="flex flex-wrap gap-2">
                    <Badge
                        variant="outline"
                        className={statusClass(stats.demand_status)}
                    >
                        <Zap className="size-3" />
                        {stats.demand_status ?? 'No DSS result'}
                    </Badge>
                    <Badge
                        variant="outline"
                        className={statusClass(stats.readiness_level)}
                    >
                        <CheckCircle2 className="size-3" />
                        {stats.readiness_level ?? 'No assessment'}
                    </Badge>
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    title="Latest Consumption"
                    value={formatNumber(stats.latest_average_consumption, ' kWh')}
                    helper={stats.latest_period}
                    icon={Activity}
                />
                <StatCard
                    title="Predicted Consumption"
                    value={formatNumber(stats.latest_predicted_consumption, ' kWh')}
                    helper={stats.forecast_issuance ?? stats.forecast_period}
                    icon={Brain}
                />
                <StatCard
                    title="Forecast Change"
                    value={
                        changeDisplay === 'Unavailable'
                            ? changeDisplay
                            : `${changeIsIncrease ? '+' : ''}${changeDisplay}`
                    }
                    helper="vs previous month"
                    icon={ChangeIcon}
                />
                <StatCard
                    title="Peak Demand"
                    value={formatNumber(stats.peak_demand_kw, ' kW')}
                    helper="latest record"
                    icon={BarChart3}
                />
            </div>

            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    title="Datasets"
                    value={stats.datasets ?? 0}
                    icon={Database}
                />
                <StatCard
                    title="Processed Records"
                    value={formatNumber(stats.processed_records ?? 0)}
                    icon={Layers}
                />
                <StatCard
                    title="Forecasts"
                    value={stats.forecasts ?? 0}
                    icon={LineChart}
                />
                <StatCard
                    title="Reports"
                    value={stats.reports ?? 0}
                    icon={FileText}
                />
            </div>

            <div className="grid gap-4 xl:grid-cols-[1.35fr_0.65fr]">
                <Card className="rounded-lg">
                    <CardHeader className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                        <div>
                            <CardTitle>Monthly Consumption Trend</CardTitle>
                            <p className="text-sm text-muted-foreground">
                                Latest 24 processed monthly records.
                            </p>
                        </div>
                        <Badge variant="secondary">
                            <LineChart className="size-3" />
                            kWh
                        </Badge>
                    </CardHeader>
                    <CardContent>
                        <ConsumptionTrendChart data={trend} />
                    </CardContent>
                </Card>

                <Card className="rounded-lg">
                    <CardHeader>
                        <CardTitle>System Snapshot</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="rounded-md border p-4">
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <ServerCog className="size-4" />
                                Forecast Period
                            </div>
                            <p className="mt-2 text-2xl font-semibold">
                                {stats.forecast_period ?? 'No forecast'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {stats.forecast_issuance}
                            </p>
                        </div>
                        <div className="rounded-md border p-4">
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Sun className="size-4" />
                                Solar Irradiance
                            </div>
                            <p className="mt-2 text-2xl font-semibold">
                                {formatNumber(stats.solar_irradiance)}
                            </p>
                        </div>
                        <div className="rounded-md border p-4">
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Activity className="size-4" />
                                Previous Consumption
                            </div>
                            <p className="mt-2 text-2xl font-semibold">
                                {formatNumber(stats.previous_consumption, ' kWh')}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div className="grid gap-4 xl:grid-cols-3">
                <Card className="rounded-lg">
                    <CardHeader>
                        <CardTitle>Yearly Average Consumption</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <YearlyAverageChart data={yearly} />
                    </CardContent>
                </Card>

                <Card className="rounded-lg">
                    <CardHeader>
                        <CardTitle>Peak Demand and Solar Condition</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <EnergyConditionChart data={trend} />
                    </CardContent>
                </Card>

                <Card className="rounded-lg">
                    <CardHeader>
                        <CardTitle>Recent Forecasts</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {recentForecasts.length > 0 ? (
                            recentForecasts.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex items-center justify-between gap-3 border-b pb-3 last:border-0 last:pb-0"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {formatPeriod(
                                                item.year,
                                                item.month,
                                            )}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {item.model_type ?? 'Forecast'} · {item.issuance_label}
                                        </p>
                                    </div>
                                    <p className="text-sm font-semibold">
                                        {formatNumber(
                                            item.predicted_consumption_kwh,
                                            ' kWh',
                                        )}
                                    </p>
                                </div>
                            ))
                        ) : (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                No recent forecasts found.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="grid gap-4 xl:grid-cols-2">
                <Card className="rounded-lg">
                    <CardHeader>
                        <CardTitle>Recent Datasets</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {recentDatasets.length > 0 ? (
                            recentDatasets.map((dataset) => (
                                <div
                                    key={dataset.id}
                                    className="flex items-center justify-between gap-3 border-b pb-3 last:border-0 last:pb-0"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {dataset.original_name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {dataset.type} -{' '}
                                            {formatNumber(dataset.record_count)}{' '}
                                            records
                                        </p>
                                    </div>
                                    <Badge
                                        variant="outline"
                                        className="shrink-0"
                                    >
                                        <UploadCloud className="size-3" />
                                        {dataset.status}
                                    </Badge>
                                </div>
                            ))
                        ) : (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                No recent datasets found.
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card className="rounded-lg">
                    <CardHeader>
                        <CardTitle>Recent Reports</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {recentReports.length > 0 ? (
                            recentReports.map((report) => (
                                <div
                                    key={report.id}
                                    className="flex items-center justify-between gap-3 border-b pb-3 last:border-0 last:pb-0"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {report.title}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {new Date(
                                                report.created_at,
                                            ).toLocaleString()}
                                        </p>
                                    </div>
                                    <Badge
                                        variant="secondary"
                                        className="shrink-0"
                                    >
                                        {report.type}
                                    </Badge>
                                </div>
                            ))
                        ) : (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                No recent reports found.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
