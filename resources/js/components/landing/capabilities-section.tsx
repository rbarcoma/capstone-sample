import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    BarChart3,
    BrainCircuit,
    Database,
    FileBarChart,
    History,
    ListChecks,
} from 'lucide-react';

import { dashboard, login } from '@/routes';

import { LandingContainer, Reveal, SectionHeading } from './landing-shared';

const capabilities = [
    {
        icon: BarChart3,
        title: 'Consumption Analytics',
        description:
            'Review monthly consumption trends, seasonal patterns, averages, and peak-demand behavior in readable visual form.',
    },
    {
        icon: Database,
        title: 'Data Management & Preprocessing',
        description:
            'Upload structured datasets, check required headers, aggregate monthly records, and retain processing status for review.',
    },
    {
        icon: BrainCircuit,
        title: 'Machine Learning Forecasting',
        description:
            'Estimate next-calendar-month electricity consumption using the platform’s active Ridge (penalty 0.1) workflow.',
    },
    {
        icon: ListChecks,
        title: 'Decision Support',
        description:
            'Separate transparent rules organize demand classifications, readiness signals, recommendations, and priority actions.',
    },
    {
        icon: FileBarChart,
        title: 'Report Generation',
        description:
            'Prepare stored, printable summaries that bring analytics, forecast results, and decision-support context together.',
    },
    {
        icon: History,
        title: 'Operational Auditability',
        description:
            'Capture major user actions, modules, timestamps, status, and request context to support accountable system operation.',
    },
];

export function CapabilitiesSection({
    isAuthenticated,
}: {
    isAuthenticated: boolean;
}) {
    const accountRoute = isAuthenticated ? dashboard() : login();

    return (
        <section id="capabilities" className="bg-white py-landing-section">
            <LandingContainer>
                <Reveal>
                    <SectionHeading
                        eyebrow="Platform capabilities"
                        title="One research workflow, organized around the decisions people need to review."
                        description="Each module has a distinct role—from preparing records and understanding consumption to estimating the next month and documenting the resulting planning guidance."
                    />
                </Reveal>

                <div className="mt-12 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    {capabilities.map((capability, index) => {
                        const Icon = capability.icon;

                        return (
                            <Reveal key={capability.title} delay={index * 45}>
                                <article className="group flex h-full flex-col rounded-landing-card border border-slate-200 bg-white p-6 shadow-landing-card transition-[transform,box-shadow,border-color] duration-300 hover:-translate-y-1 hover:border-energy-green-200 hover:shadow-landing-card-hover">
                                    <span className="flex size-12 items-center justify-center rounded-2xl border border-energy-green-200 bg-energy-green-50 text-energy-green-700 transition-colors group-hover:bg-energy-green-100">
                                        <Icon
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <h3 className="mt-6 text-xl leading-7 font-bold tracking-[-0.025em] text-energy-navy-950">
                                        {capability.title}
                                    </h3>
                                    <p className="mt-3 flex-1 text-sm leading-6 text-slate-600">
                                        {capability.description}
                                    </p>
                                    <Link
                                        href={accountRoute}
                                        className="mt-6 inline-flex min-h-11 w-fit items-center gap-2 rounded-lg text-sm font-bold text-energy-green-800 outline-none hover:text-energy-navy-950 focus-visible:ring-2 focus-visible:ring-energy-green-500 focus-visible:ring-offset-4"
                                    >
                                        {isAuthenticated
                                            ? 'Open module access'
                                            : 'Log in to explore'}
                                        <ArrowUpRight
                                            className="size-4 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </article>
                            </Reveal>
                        );
                    })}
                </div>
            </LandingContainer>
        </section>
    );
}
