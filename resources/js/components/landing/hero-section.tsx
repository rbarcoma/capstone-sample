import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    Building2,
    CheckCircle2,
    CircleGauge,
    Leaf,
    LockKeyhole,
    MapPinned,
    Microscope,
    SunMedium,
} from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

import type { LandingSnapshot } from './landing-data';
import { formatEnergy, LandingContainer, SourceBadge } from './landing-shared';

const trustSignals = [
    { icon: MapPinned, label: 'Quezon City study scope' },
    { icon: BarChart3, label: 'Data-driven analytics' },
    { icon: LockKeyhole, label: 'Explainable decision rules' },
    { icon: Microscope, label: 'Academic research platform' },
];

function MiniTrendChart({ snapshot }: { snapshot: LandingSnapshot }) {
    const [windowSize, setWindowSize] = useState<6 | 12>(12);
    const visibleData = snapshot.trend.slice(-windowSize);
    const values = visibleData.map((point) => point.consumptionKwh);

    if (!values.length) {
        return (
            <div className="flex h-44 items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 text-center text-sm text-slate-600">
                Consumption trend data will appear when a dataset is connected.
            </div>
        );
    }

    const minimum = Math.min(...values) * 0.96;
    const maximum = Math.max(...values) * 1.04;
    const range = Math.max(maximum - minimum, 1);
    const chartPoints = visibleData.map((point, index) => {
        const x =
            visibleData.length === 1
                ? 250
                : 24 + (index / (visibleData.length - 1)) * 452;
        const y = 142 - ((point.consumptionKwh - minimum) / range) * 112;

        return { ...point, x, y };
    });
    const pointString = chartPoints
        .map((point) => `${point.x},${point.y}`)
        .join(' ');
    const areaString = `24,152 ${pointString} 476,152`;
    const firstPoint = chartPoints[0]!;
    const lastPoint = chartPoints.at(-1)!;

    return (
        <div>
            <div className="mb-4 flex items-start justify-between gap-4">
                <div>
                    <p className="text-sm font-bold text-energy-navy-950">
                        Consumption trend
                    </p>
                    <p className="mt-1 text-xs text-slate-500">
                        Monthly average · kWh
                    </p>
                </div>
                <div
                    className="inline-flex rounded-lg bg-slate-100 p-1"
                    aria-label="Trend range"
                >
                    {([6, 12] as const).map((value) => (
                        <button
                            key={value}
                            type="button"
                            className="min-h-8 rounded-md px-2.5 text-xs font-bold text-slate-600 transition-colors outline-none hover:text-energy-navy-950 focus-visible:ring-2 focus-visible:ring-energy-green-500 aria-pressed:bg-white aria-pressed:text-energy-navy-950 aria-pressed:shadow-sm"
                            aria-pressed={windowSize === value}
                            onClick={() => setWindowSize(value)}
                        >
                            {value}M
                        </button>
                    ))}
                </div>
            </div>

            <div className="relative h-44 w-full overflow-hidden rounded-2xl bg-[linear-gradient(180deg,#f8fbfa_0%,#f1f7f4_100%)]">
                <svg
                    viewBox="0 0 500 176"
                    role="img"
                    aria-labelledby="hero-trend-title hero-trend-description"
                    className="size-full"
                    preserveAspectRatio="none"
                >
                    <title id="hero-trend-title">
                        Illustrative monthly electricity-consumption trend
                    </title>
                    <desc id="hero-trend-description">
                        {`The visible sample ranges from ${formatEnergy(firstPoint.consumptionKwh)} to ${formatEnergy(lastPoint.consumptionKwh)} kilowatt-hours.`}
                    </desc>
                    <defs>
                        <linearGradient
                            id="heroTrendArea"
                            x1="0"
                            x2="0"
                            y1="0"
                            y2="1"
                        >
                            <stop
                                offset="0%"
                                stopColor="#1b9a68"
                                stopOpacity="0.22"
                            />
                            <stop
                                offset="100%"
                                stopColor="#1b9a68"
                                stopOpacity="0"
                            />
                        </linearGradient>
                    </defs>
                    {[42, 80, 118, 152].map((y) => (
                        <line
                            key={y}
                            x1="24"
                            x2="476"
                            y1={y}
                            y2={y}
                            stroke="#dce7e2"
                            strokeDasharray="4 6"
                            strokeWidth="1"
                        />
                    ))}
                    <polygon points={areaString} fill="url(#heroTrendArea)" />
                    <polyline
                        points={pointString}
                        fill="none"
                        stroke="#137a53"
                        strokeWidth="3.5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        vectorEffect="non-scaling-stroke"
                    />
                    {chartPoints.map((point, index) => (
                        <circle
                            key={`${point.period}-${point.consumptionKwh}`}
                            cx={point.x}
                            cy={point.y}
                            r={index === chartPoints.length - 1 ? 5 : 2.5}
                            fill={
                                index === chartPoints.length - 1
                                    ? '#ffffff'
                                    : '#137a53'
                            }
                            stroke="#137a53"
                            strokeWidth={
                                index === chartPoints.length - 1 ? 3 : 1.5
                            }
                            vectorEffect="non-scaling-stroke"
                        />
                    ))}
                </svg>
                <div className="pointer-events-none absolute inset-x-5 bottom-2 flex justify-between text-[0.625rem] font-bold tracking-wide text-slate-500 uppercase">
                    <span>{firstPoint.period}</span>
                    <span>{lastPoint.period}</span>
                </div>
            </div>
        </div>
    );
}

function AnalyticsPreview({ snapshot }: { snapshot: LandingSnapshot }) {
    const forecast = snapshot.forecast;
    const decisionSupport = snapshot.decisionSupport;

    return (
        <div className="relative">
            <div
                className="absolute -inset-4 rounded-[2rem] bg-energy-green-500/10 blur-2xl"
                aria-hidden="true"
            />
            <div className="relative overflow-hidden rounded-[1.75rem] border border-white/70 bg-white p-4 text-energy-navy-950 shadow-[0_32px_80px_-36px_rgba(0,0,0,0.65)] sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-5">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-energy-green-700 uppercase">
                            Analytics workspace
                        </p>
                        <h2 className="mt-2 text-xl font-bold tracking-[-0.025em]">
                            Planning overview
                        </h2>
                    </div>
                    <div className="inline-flex items-center gap-2 rounded-full border border-energy-green-200 bg-energy-green-50 px-3 py-1.5 text-xs font-bold text-energy-green-800">
                        <span className="size-1.5 rounded-full bg-energy-green-600" />
                        Preview
                    </div>
                </div>

                <div className="grid gap-3 py-5 sm:grid-cols-2">
                    <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div className="flex items-center justify-between gap-3">
                            <p className="text-xs font-semibold text-slate-500">
                                Next-month forecast
                            </p>
                            <BarChart3
                                className="size-4 text-energy-green-700"
                                aria-hidden="true"
                            />
                        </div>
                        <p className="mt-3 text-2xl font-bold tracking-[-0.04em]">
                            {forecast
                                ? formatEnergy(forecast.predictedConsumptionKwh)
                                : '—'}
                            {forecast && (
                                <span className="ml-1 text-sm font-semibold text-slate-500">
                                    kWh
                                </span>
                            )}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                            {forecast?.modelType ?? 'Awaiting forecast data'}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div className="flex items-center justify-between gap-3">
                            <p className="text-xs font-semibold text-slate-500">
                                Demand classification
                            </p>
                            <CircleGauge
                                className="size-4 text-energy-green-700"
                                aria-hidden="true"
                            />
                        </div>
                        <p className="mt-3 text-lg font-bold">
                            {decisionSupport?.demandStatus ?? 'Not available'}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                            Rule-based screening output
                        </p>
                    </div>
                </div>

                <MiniTrendChart snapshot={snapshot} />

                <div className="mt-5 grid gap-3 sm:grid-cols-2">
                    <div className="flex items-center gap-3 rounded-2xl border border-slate-200 px-4 py-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-energy-green-50 text-energy-green-700">
                            <Leaf className="size-4" aria-hidden="true" />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-[0.6875rem] font-semibold text-slate-500">
                                Renewable readiness
                            </span>
                            <span className="mt-0.5 block truncate text-sm font-bold">
                                {decisionSupport?.readinessLevel ??
                                    'Not available'}
                            </span>
                        </span>
                    </div>
                    <div className="flex items-center gap-3 rounded-2xl border border-slate-200 px-4 py-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
                            <SunMedium className="size-4" aria-hidden="true" />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-[0.6875rem] font-semibold text-slate-500">
                                Solar potential signal
                            </span>
                            <span className="mt-0.5 block truncate text-sm font-bold">
                                {decisionSupport?.solarSignal ??
                                    'Not available'}
                            </span>
                        </span>
                    </div>
                </div>

                <div className="mt-5">
                    <SourceBadge label={snapshot.sourceLabel} />
                </div>
            </div>
        </div>
    );
}

export function HeroSection({
    canRegister,
    isAuthenticated,
    snapshot,
}: {
    canRegister: boolean;
    isAuthenticated: boolean;
    snapshot: LandingSnapshot;
}) {
    const accountRoute = isAuthenticated ? dashboard() : login();

    return (
        <section
            id="home"
            className="relative isolate overflow-hidden bg-energy-navy-950 text-white"
        >
            <div
                className="absolute inset-0 -z-20 bg-[radial-gradient(circle_at_82%_20%,rgba(27,154,104,0.2),transparent_34%),radial-gradient(circle_at_12%_88%,rgba(46,112,142,0.15),transparent_32%)]"
                aria-hidden="true"
            />
            <div
                className="landing-grid-pattern absolute inset-0 -z-10 opacity-35"
                aria-hidden="true"
            />

            <LandingContainer className="grid min-h-[calc(100svh-4.75rem)] items-center gap-14 py-16 lg:grid-cols-[minmax(0,1.02fr)_minmax(28rem,0.98fr)] lg:py-24 xl:gap-20">
                <div className="max-w-3xl">
                    <div className="inline-flex items-center gap-2 rounded-full border border-energy-green-400/30 bg-energy-green-400/10 px-3 py-1.5 text-xs font-bold tracking-[0.12em] text-energy-green-100 uppercase">
                        <Building2 className="size-3.5" aria-hidden="true" />
                        Quezon City · clean-energy decision support
                    </div>

                    <h1 className="mt-7 max-w-3xl text-landing-display font-semibold text-balance">
                        Data-driven renewable-energy planning for Quezon City.
                    </h1>
                    <p className="mt-6 max-w-2xl text-base leading-7 text-slate-300 sm:text-lg sm:leading-8">
                        DSS Energy turns historical electricity and climate data
                        into clear consumption analytics, a Ridge (penalty 0.1)
                        estimate for the next month, and transparent rule-based
                        recommendations for planning conversations.
                    </p>

                    <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <Button
                            asChild
                            className="h-13 rounded-xl bg-energy-green-700 px-6 text-[0.95rem] font-bold text-white shadow-lg shadow-energy-green-950/20 hover:bg-energy-green-800 focus-visible:ring-white"
                        >
                            <a href="#snapshot">
                                Explore the platform
                                <ArrowRight className="size-4" />
                            </a>
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            className="h-13 rounded-xl border-white/20 bg-white/5 px-6 text-[0.95rem] font-bold text-white hover:border-white/35 hover:bg-white/10 hover:text-white focus-visible:ring-energy-green-300"
                        >
                            <Link href={accountRoute}>
                                {isAuthenticated ? 'Open dashboard' : 'Log in'}
                            </Link>
                        </Button>
                    </div>

                    {!isAuthenticated && canRegister && (
                        <p className="mt-4 text-sm text-slate-400">
                            New to the research platform?{' '}
                            <Link
                                href={register()}
                                className="rounded-sm font-semibold text-energy-green-200 underline decoration-energy-green-300/50 underline-offset-4 outline-none hover:text-white focus-visible:ring-2 focus-visible:ring-energy-green-300"
                            >
                                Create an account
                            </Link>
                            .
                        </p>
                    )}

                    <div className="mt-10 grid gap-x-6 gap-y-4 border-t border-white/10 pt-7 sm:grid-cols-2">
                        {trustSignals.map(({ icon: Icon, label }) => (
                            <div
                                key={label}
                                className="flex items-center gap-2.5 text-sm font-medium text-slate-300"
                            >
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-white/6 text-energy-green-300">
                                    <Icon
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                </span>
                                {label}
                            </div>
                        ))}
                    </div>

                    <div className="mt-8 flex items-start gap-3 rounded-2xl border border-white/10 bg-white/[0.04] p-4 text-sm leading-6 text-slate-300">
                        <CheckCircle2
                            className="mt-0.5 size-4 shrink-0 text-energy-green-300"
                            aria-hidden="true"
                        />
                        <p>
                            Built to support evidence-based review. It does not
                            control infrastructure or guarantee policy outcomes.
                        </p>
                    </div>
                </div>

                <AnalyticsPreview snapshot={snapshot} />
            </LandingContainer>
        </section>
    );
}
