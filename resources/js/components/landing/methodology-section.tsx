import {
    BarChart3,
    BrainCircuit,
    Building2,
    Database,
    FileCheck2,
    Scale,
    ShieldCheck,
} from 'lucide-react';

import { LandingContainer, Reveal } from './landing-shared';

const methodologyItems = [
    {
        icon: Building2,
        label: 'Study scope',
        title: 'Quezon City context',
        description:
            'A monthly aggregate research series is used to frame electricity-consumption planning in the Quezon City study context.',
    },
    {
        icon: Database,
        label: 'Data foundation',
        title: 'Electricity and climate records',
        description:
            'Consumption, temperature, humidity, rainfall, solar irradiance, and peak demand provide the model and analytics inputs.',
    },
    {
        icon: BrainCircuit,
        label: 'Forecast model',
        title: 'Ridge (penalty 0.1)',
        description:
            'The active workflow standardizes lag, trend, seasonal, climate, solar, and peak-demand features for a next-month estimate.',
    },
    {
        icon: BarChart3,
        label: 'Model review',
        title: 'MAE, RMSE, and R²',
        description:
            'Forecast error and goodness-of-fit metrics are calculated and stored for technical review without presenting them as guaranteed accuracy.',
    },
    {
        icon: FileCheck2,
        label: 'Recommendation logic',
        title: 'Transparent decision rules',
        description:
            'Demand classification and readiness screening are generated separately from the ML estimate through inspectable study-defined rules.',
    },
    {
        icon: ShieldCheck,
        label: 'Quality framework',
        title: 'ISO/IEC 25010 evaluation lens',
        description:
            'The standard provides the study’s system-quality evaluation framework; this wording does not imply certification or a completed compliance claim.',
    },
];

export function MethodologySection() {
    return (
        <section
            id="methodology"
            className="relative isolate overflow-hidden bg-energy-navy-950 py-landing-section text-white"
        >
            <div
                className="absolute inset-0 -z-20 bg-[radial-gradient(circle_at_85%_10%,rgba(27,154,104,0.16),transparent_30%),radial-gradient(circle_at_15%_90%,rgba(66,153,189,0.12),transparent_30%)]"
                aria-hidden="true"
            />
            <div
                className="landing-grid-pattern absolute inset-0 -z-10 opacity-20"
                aria-hidden="true"
            />
            <LandingContainer>
                <div className="grid gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:gap-16">
                    <Reveal>
                        <div className="lg:sticky lg:top-28">
                            <p className="landing-eyebrow text-energy-green-300">
                                Methodology & credibility
                            </p>
                            <h2 className="mt-4 text-3xl leading-tight font-semibold tracking-[-0.035em] text-white sm:text-4xl lg:text-[2.75rem]">
                                Research choices made visible for technical and
                                institutional review.
                            </h2>
                            <p className="mt-5 text-base leading-7 text-slate-300 sm:text-lg sm:leading-8">
                                Credibility comes from a clear scope, an
                                explainable workflow, appropriate metrics, and
                                honest limits—not from presenting a model output
                                as a final policy answer.
                            </p>

                            <div className="mt-8 rounded-2xl border border-white/10 bg-white/[0.05] p-5">
                                <p className="text-xs font-bold tracking-[0.13em] text-energy-green-300 uppercase">
                                    Full capstone research title
                                </p>
                                <p className="mt-3 text-sm leading-6 font-medium text-slate-200">
                                    Decision Support System for Renewable Energy
                                    Transition in Quezon City Based on Average
                                    Electricity Consumption Analytics and
                                    Machine Learning-Based Next-Month Energy
                                    Consumption Forecasting
                                </p>
                            </div>
                        </div>
                    </Reveal>

                    <div className="grid gap-4 sm:grid-cols-2">
                        {methodologyItems.map((item, index) => {
                            const Icon = item.icon;

                            return (
                                <Reveal key={item.title} delay={index * 50}>
                                    <article className="h-full rounded-landing-card border border-white/10 bg-white/[0.06] p-5 backdrop-blur-sm transition-colors hover:border-energy-green-300/30 hover:bg-white/[0.08] sm:p-6">
                                        <div className="flex items-center justify-between gap-4">
                                            <span className="flex size-10 items-center justify-center rounded-xl border border-white/10 bg-white/[0.07] text-energy-green-300">
                                                <Icon
                                                    className="size-5"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                            <span className="text-[0.625rem] font-bold tracking-[0.14em] text-slate-400 uppercase">
                                                {item.label}
                                            </span>
                                        </div>
                                        <h3 className="mt-6 text-lg leading-7 font-bold tracking-[-0.02em] text-white">
                                            {item.title}
                                        </h3>
                                        <p className="mt-3 text-sm leading-6 text-slate-300">
                                            {item.description}
                                        </p>
                                    </article>
                                </Reveal>
                            );
                        })}
                    </div>
                </div>

                <Reveal className="mt-10">
                    <aside className="grid gap-5 rounded-[1.75rem] border border-energy-green-300/20 bg-energy-green-300/10 p-5 sm:p-7 lg:grid-cols-[auto_1fr] lg:items-start">
                        <span className="flex size-12 items-center justify-center rounded-2xl bg-energy-green-300 text-energy-navy-950">
                            <Scale className="size-5" aria-hidden="true" />
                        </span>
                        <div>
                            <h3 className="text-lg font-bold text-white">
                                Decision-support, not decision replacement
                            </h3>
                            <p className="mt-2 max-w-5xl text-sm leading-6 text-slate-200">
                                Platform outputs should be interpreted with
                                relevant engineering, planning, financial,
                                environmental, and policy expertise. Solar
                                irradiance is a screening input—not a rooftop PV
                                design, yield estimate, procurement case, or
                                feasibility determination.
                            </p>
                        </div>
                    </aside>
                </Reveal>
            </LandingContainer>
        </section>
    );
}
