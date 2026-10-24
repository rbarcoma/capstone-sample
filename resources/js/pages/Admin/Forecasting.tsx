import { displayValue } from '@/lib/display-value';
import { StatCard } from '@/components/dss/stat-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { ForecastResult } from '@/types';
import { router, usePage } from '@inertiajs/react';
import {
    Brain,
    CheckCircle2,
    LineChart,
    LoaderCircle,
    Search,
    Target,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const monthNames = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

const trainingSteps = [
    {
        title: 'Exporting processed records',
        detail: 'Preparing the monthly processed dataset for model training.',
    },
    {
        title: 'Loading dataset',
        detail: 'Reading electricity, weather, solar, and peak-demand records.',
    },
    {
        title: 'Preparing features',
        detail: 'Building lag, trend, and seasonal month features for regression.',
    },
    {
        title: 'Splitting train and test data',
        detail: 'Separating historical records for model training and validation.',
    },
    {
        title: 'Training Ridge (penalty 0.1)',
        detail: 'Standardizing values and solving the model coefficients.',
    },
    {
        title: 'Running cross-validation',
        detail: 'Training and validating the model across multiple dataset folds.',
    },
    {
        title: 'Evaluating and saving output',
        detail: 'Calculating MAE, RMSE, R2 score, then saving the model and metrics.',
    },
];

export default function Forecasting({
    latestForecast,
    forecastHistory = [],
    metricDefinitions,
}: {
    latestForecast?: ForecastResult | null;
    forecastHistory: ForecastResult[];
    metricDefinitions: Record<string, string>;
}) {
    const { flash } = usePage().props as any;

    const [infoModal, setInfoModal] = useState<{
        title: string;
        content: string;
    } | null>(null);
    const [isTraining, setIsTraining] = useState(false);
    const [trainingStep, setTrainingStep] = useState(0);

    const [search, setSearch] = useState('');
    const [monthFilter, setMonthFilter] = useState('all');
    const [currentPage, setCurrentPage] = useState(1);

    const [itemsPerPage, setItemsPerPage] = useState(10);
    const latestForecastId = latestForecast?.id;

    useEffect(() => {
        if (!isTraining) {
            return;
        }

        const interval = window.setInterval(() => {
            setTrainingStep((currentStep) =>
                Math.min(currentStep + 1, trainingSteps.length - 1),
            );
        }, 1400);

        return () => window.clearInterval(interval);
    }, [isTraining]);

    const trainModel = () => {
        setTrainingStep(0);
        setIsTraining(true);

        router.post(
            '/admin/forecasting/train',
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setTrainingStep(trainingSteps.length - 1);
                    window.setTimeout(() => setIsTraining(false), 900);
                },
            },
        );
    };

    const formatPredictionDate = (value?: string) => {
        if (!value) {
            return '-';
        }

        return new Intl.DateTimeFormat('en-PH', {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(value));
    };

    const filteredForecasts = useMemo(() => {
        return forecastHistory.filter((item) => {
            const period = `${item.year}-${String(item.month).padStart(2, '0')}`;
            const monthName = monthNames[item.month - 1]?.toLowerCase() ?? '';
            const model = item.model_type?.toLowerCase() ?? '';
            const predictedBy = item.user?.name?.toLowerCase() ?? 'system';
            const predictedAt = formatPredictionDate(
                item.predicted_at ?? item.created_at,
            ).toLowerCase();

            const matchesSearch =
                period.includes(search.toLowerCase()) ||
                monthName.includes(search.toLowerCase()) ||
                model.includes(search.toLowerCase()) ||
                predictedBy.includes(search.toLowerCase()) ||
                predictedAt.includes(search.toLowerCase());

            const matchesMonth =
                monthFilter === 'all' || item.month === Number(monthFilter);

            return matchesSearch && matchesMonth;
        });
    }, [forecastHistory, search, monthFilter]);

    const totalPages = Math.ceil(filteredForecasts.length / itemsPerPage);

    const paginatedForecasts = filteredForecasts.slice(
        (currentPage - 1) * itemsPerPage,
        currentPage * itemsPerPage,
    );

    const forecastingInfo = {
        predictionNextMonth: {
            title: 'Dataset-next-month Estimate',
            content:
                'Prediction Next Month is the estimated electricity consumption for the month immediately after the latest complete actual dataset month generated by the trained Ridge (penalty 0.1) model.\n\nRidge (penalty 0.1) Formula:\npredicted_consumption = intercept + (coefficient_1 × feature_1) + (coefficient_2 × feature_2) + ... + (coefficient_n × feature_n)\n\nIn this system:\npredicted_consumption = intercept + b1(year) + b2(month) + b3(temperature) + b4(humidity) + b5(rainfall) + b6(solar_irradiance) + b7(peak_demand_kw) + b8(lag_1) + b9(lag_2) + b10(trend) + b11(month_sin) + b12(month_cos)\n\nFeatures Used:\nyear, month, temperature, humidity, rainfall, solar_irradiance, peak_demand_kw, lag_1, lag_2, trend, month_sin, and month_cos\n\nHow the next-month prediction is obtained:\n1. Daily uploaded records are aggregated into monthly records.\n2. Preprocessing prepares the monthly dataset and creates forecasting features.\n3. lag_1 uses the previous month consumption, while lag_2 uses the consumption from two months before.\n4. month_sin and month_cos represent the seasonal pattern of the month.\n5. During training, Ridge (penalty 0.1) learns the intercept and coefficient value for each feature from historical monthly records.\n6. For the next month, the system builds one feature row using the latest available data and the next month period.\n7. The learned coefficients are multiplied by the next-month feature values, then added with the intercept.\n8. The final computed value becomes the Prediction Next Month result.',
        },
        mae: {
            title: 'MAE',
            content: `${metricDefinitions.mae} ${metricDefinitions.scope}`,
        },
        r2Score: {
            title: 'R2 Score',
            content: `${metricDefinitions.r2_score} ${metricDefinitions.fold_average} ${metricDefinitions.scope} ${metricDefinitions.legacy}`,
        },
        rmse: {
            title: 'RMSE',
            content: `${metricDefinitions.rmse} ${metricDefinitions.scope}`,
        },
    };

    return (
        <div className="space-y-6 p-6">
            <div className="flex items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Machine Learning Forecasting
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Estimate consumption for the month after the latest complete actual dataset month.
                    </p>
                </div>

                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        disabled={isTraining}
                        onClick={trainModel}
                    >
                        {isTraining ? (
                            <>
                                <LoaderCircle className="h-4 w-4 animate-spin" />
                                Training
                            </>
                        ) : (
                            'Train Model'
                        )}
                    </Button>

                    <Button
                        disabled={isTraining}
                        onClick={() => router.post('/admin/forecasting/predict')}
                    >
                        Predict Next Month
                    </Button>
                </div>
            </div>

            {flash?.success && (
                <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            {flash?.error && (
                <div className="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                    {flash.error}
                </div>
            )}

            <div className="grid gap-4 md:grid-cols-4">
                <StatCard
                    title="Dataset-next-month Estimate"
                    icon={Brain}
                    value={displayValue(latestForecast?.predicted_consumption_kwh, ' kWh')}
                    helper={latestForecast?.issuance_label}
                    onInfoClick={() => setInfoModal(forecastingInfo.predictionNextMonth)}
                />

                <StatCard
                    title="MAE"
                    value={latestForecast?.mae ?? 'Unavailable'}
                    icon={Target}
                    onInfoClick={() => setInfoModal(forecastingInfo.mae)}
                />

                <StatCard
                    title="R2 Score"
                    value={latestForecast?.r2_score ?? 'Unavailable'}
                    icon={LineChart}
                    onInfoClick={() => setInfoModal(forecastingInfo.r2Score)}
                />

                <StatCard
                    title="RMSE"
                    value={latestForecast?.rmse ?? 'Unavailable'}
                    icon={Target}
                    onInfoClick={() => setInfoModal(forecastingInfo.rmse)}
                />
            </div>

            <Card className="rounded-lg">
                <CardHeader>
                    <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <CardTitle>Forecast History Table</CardTitle>

                        <div className="flex flex-col gap-2 md:flex-row">
                            <div className="relative">
                                <Search className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />

                                <Input
                                    className="w-full pl-9 md:w-64"
                                    placeholder="Search period or model..."
                                    value={search}
                                    onChange={(event) => {
                                        setSearch(event.target.value);
                                        setCurrentPage(1);
                                    }}
                                />
                            </div>

                            <select
                                className="rounded-md border bg-background px-3 py-2 text-sm"
                                value={monthFilter}
                                onChange={(event) => {
                                    setMonthFilter(event.target.value);
                                    setCurrentPage(1);
                                }}
                            >
                                <option value="all">All Months</option>

                                {monthNames.map((month, index) => (
                                    <option key={month} value={index + 1}>
                                        {month}
                                    </option>
                                ))}
                            </select>
                            <select
                                className="rounded-md border bg-background px-3 py-2 text-sm"
                                value={itemsPerPage}
                                onChange={(event) => {
                                    setItemsPerPage(Number(event.target.value));
                                    setCurrentPage(1);
                                }}
                            >
                                <option value={10}>10</option>
                                <option value={50}>50</option>
                                <option value={100}>100</option>
                            </select>
                        </div>
                    </div>
                </CardHeader>

                <CardContent className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/40 text-left">
                                <th className="px-3 py-3 font-semibold">Period</th>
                                <th className="px-3 py-3 font-semibold">Prediction</th>
                                <th className="px-3 py-3 font-semibold">Change</th>
                                <th className="px-3 py-3 font-semibold">RMSE</th>
                                <th className="px-3 py-3 font-semibold">Model</th>
                                <th className="px-3 py-3 font-semibold">Predicted By</th>
                                <th className="px-3 py-3 font-semibold">Produced</th>

                            </tr>
                        </thead>

                        <tbody>
                            {paginatedForecasts.length > 0 ? (
                                paginatedForecasts.map((item) => {
                                    const isLatest = item.id === latestForecastId;

                                    return (
                                        <tr
                                            key={item.id}
                                            className={`border-b ${isLatest ? 'bg-primary/5 font-bold' : ''}`}
                                        >
                                            <td className="px-3 py-3">
                                                {item.year}-{String(item.month).padStart(2, '0')}
                                            </td>
                                            <td className="px-3 py-3">
                                                {displayValue(item.predicted_consumption_kwh, ' kWh')}
                                            </td>
                                            <td className="px-3 py-3">
                                                {displayValue(item.change_percent, '%')}
                                            </td>
                                            <td className="px-3 py-3">
                                                {item.rmse ?? 'Unavailable'}
                                            </td>
                                            <td className="px-3 py-3">
                                                {item.model_type}
                                            </td>
                                            <td className="px-3 py-3">
                                                {item.user?.name ?? 'System'}
                                            </td>
                                            <td className="px-3 py-3">
                                                {item.issuance_label ?? 'Production time unavailable'}
                                            </td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="px-3 py-8 text-center text-muted-foreground"
                                    >
                                        No forecast history found.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>

                    <div className="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {paginatedForecasts.length} of{' '}
                            {filteredForecasts.length} forecasts
                        </p>

                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={currentPage === 1}
                                onClick={() => setCurrentPage(currentPage - 1)}
                            >
                                Previous
                            </Button>

                            <span className="text-sm">
                                Page {currentPage} of {totalPages || 1}
                            </span>

                            <Button
                                variant="outline"
                                size="sm"
                                disabled={
                                    currentPage === totalPages ||
                                    totalPages === 0
                                }
                                onClick={() => setCurrentPage(currentPage + 1)}
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Dialog open={!!infoModal} onOpenChange={() => setInfoModal(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{infoModal?.title}</DialogTitle>
                    </DialogHeader>

                    <p className="whitespace-pre-line text-sm leading-relaxed text-muted-foreground">
                        {infoModal?.content}
                    </p>
                </DialogContent>
            </Dialog>

            <Dialog open={isTraining} onOpenChange={() => undefined}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Training Machine Learning Model</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-5">
                        <div>
                            <div className="mb-2 flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    Step {trainingStep + 1} of {trainingSteps.length}
                                </span>
                                <span className="font-medium">
                                    {Math.round(
                                        ((trainingStep + 1) / trainingSteps.length) * 100,
                                    )}
                                    %
                                </span>
                            </div>
                            <div className="h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-emerald-500 transition-all duration-500"
                                    style={{
                                        width: `${((trainingStep + 1) / trainingSteps.length) * 100}%`,
                                    }}
                                />
                            </div>
                        </div>

                        <div className="space-y-3">
                            {trainingSteps.map((step, index) => {
                                const isComplete = index < trainingStep;
                                const isCurrent = index === trainingStep;

                                return (
                                    <div
                                        key={step.title}
                                        className={`flex gap-3 rounded-lg border p-3 ${
                                            isCurrent
                                                ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30'
                                                : ''
                                        }`}
                                    >
                                        <div className="mt-0.5">
                                            {isComplete ? (
                                                <CheckCircle2 className="h-5 w-5 text-emerald-600" />
                                            ) : isCurrent ? (
                                                <LoaderCircle className="h-5 w-5 animate-spin text-emerald-600" />
                                            ) : (
                                                <div className="h-5 w-5 rounded-full border border-muted-foreground/40" />
                                            )}
                                        </div>
                                        <div>
                                            <p className="font-medium">{step.title}</p>
                                            <p className="text-sm text-muted-foreground">
                                                {step.detail}
                                            </p>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}
