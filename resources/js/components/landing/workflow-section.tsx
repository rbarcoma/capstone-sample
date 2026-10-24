import {
    BarChart3,
    BrainCircuit,
    ChevronDown,
    Database,
    FileCheck2,
    ListChecks,
} from 'lucide-react';

import { LandingContainer, Reveal, SectionHeading } from './landing-shared';

const workflowSteps = [
    {
        icon: Database,
        title: 'Data collection',
        description:
            'Historical electricity-consumption and climate records are assembled for the study period.',
    },
    {
        icon: FileCheck2,
        title: 'Preprocessing & validation',
        description:
            'Required file headers are checked and valid records are aggregated into a monthly series.',
    },
    {
        icon: BarChart3,
        title: 'Consumption analytics',
        description:
            'Trends, seasonal patterns, averages, and peak-demand signals are organized for review.',
    },
    {
        icon: BrainCircuit,
        title: 'Next-month forecasting',
        description:
            'Standardized Ridge (penalty 0.1) uses lag, trend, seasonality, and contextual features to estimate the next month.',
    },
    {
        icon: ListChecks,
        title: 'Decision support',
        description:
            'Separate, transparent rules translate the forecast and input signals into classifications and recommendations.',
    },
];

const glossary = [
    {
        term: 'MAE',
        definition:
            'Mean Absolute Error is the average size of forecast errors, expressed in kWh. Lower values indicate smaller average errors for the evaluated data.',
    },
    {
        term: 'RMSE',
        definition:
            'Root Mean Squared Error is an error measure in kWh that gives greater weight to larger misses. Lower values are generally preferable.',
    },
    {
        term: 'R²',
        definition:
            'R-squared is a unitless measure of how much variation the model explains for the evaluated data. It is not an accuracy percentage.',
    },
    {
        term: 'Demand classification',
        definition:
            'A study-defined category that interprets the next-month estimate against the historical reference used by the platform.',
    },
    {
        term: 'Renewable readiness',
        definition:
            'A study-specific screening label based on the forecast and selected demand and solar-irradiance signals. It is not a certification or feasibility study.',
    },
];

export function WorkflowSection() {
    return (
        <section id="workflow" className="bg-energy-mist py-landing-section">
            <LandingContainer>
                <Reveal>
                    <SectionHeading
                        align="center"
                        eyebrow="How the system works"
                        title="A traceable path from source records to planning recommendations."
                        description="Machine learning is responsible for the next-month consumption estimate. Final classifications and recommendations are produced separately through reviewable decision rules."
                    />
                </Reveal>

                <ol className="relative mt-12 grid gap-4 lg:grid-cols-5">
                    <div
                        className="absolute top-7 right-[10%] left-[10%] hidden h-px bg-slate-300 lg:block"
                        aria-hidden="true"
                    />
                    {workflowSteps.map((step, index) => {
                        const Icon = step.icon;

                        return (
                            <li key={step.title} className="relative">
                                <Reveal delay={index * 60}>
                                    <article className="h-full rounded-landing-card border border-slate-200 bg-white p-5 shadow-landing-card">
                                        <div className="relative z-10 flex items-center justify-between gap-3">
                                            <span className="flex size-14 items-center justify-center rounded-2xl border border-energy-green-200 bg-energy-green-50 text-energy-green-700 shadow-[0_0_0_7px_#f3f7f6]">
                                                <Icon
                                                    className="size-5"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                            <span className="text-xs font-bold tracking-[0.12em] text-slate-400 uppercase">
                                                0{index + 1}
                                            </span>
                                        </div>
                                        <h3 className="mt-7 text-base leading-6 font-bold text-energy-navy-950">
                                            {step.title}
                                        </h3>
                                        <p className="mt-3 text-sm leading-6 text-slate-600">
                                            {step.description}
                                        </p>
                                    </article>
                                </Reveal>
                            </li>
                        );
                    })}
                </ol>

                <Reveal className="mt-12 rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-landing-card sm:p-7">
                    <div className="flex flex-col justify-between gap-3 border-b border-slate-200 pb-5 sm:flex-row sm:items-end">
                        <div>
                            <p className="landing-eyebrow">
                                Technical glossary
                            </p>
                            <h3 className="mt-3 text-xl font-bold tracking-[-0.025em] text-energy-navy-950">
                                Evaluation and decision-support terms
                            </h3>
                        </div>
                        <p className="text-sm text-slate-500">
                            Expand a term for a plain-language explanation.
                        </p>
                    </div>
                    <div className="mt-3 divide-y divide-slate-200">
                        {glossary.map((item) => (
                            <details
                                key={item.term}
                                className="group rounded-xl px-1 open:bg-slate-50"
                            >
                                <summary className="flex min-h-12 cursor-pointer list-none items-center justify-between gap-4 rounded-xl px-3 py-3 text-sm font-bold text-energy-navy-950 outline-none marker:hidden hover:text-energy-green-800 focus-visible:ring-2 focus-visible:ring-energy-green-500 [&::-webkit-details-marker]:hidden">
                                    {item.term}
                                    <ChevronDown
                                        className="size-4 shrink-0 text-slate-500 transition-transform group-open:rotate-180"
                                        aria-hidden="true"
                                    />
                                </summary>
                                <p className="px-3 pb-4 text-sm leading-6 text-slate-600">
                                    {item.definition}
                                </p>
                            </details>
                        ))}
                    </div>
                </Reveal>
            </LandingContainer>
        </section>
    );
}
