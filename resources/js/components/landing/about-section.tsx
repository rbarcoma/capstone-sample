import {
    BarChart3,
    Building2,
    CalendarRange,
    CheckCircle2,
    Database,
    Leaf,
    SunMedium,
} from 'lucide-react';

import { LandingContainer, Reveal, SectionHeading } from './landing-shared';

const studyHighlights = [
    {
        icon: Building2,
        label: 'Study context',
        value: 'Quezon City',
    },
    {
        icon: CalendarRange,
        label: 'Forecast horizon',
        value: 'Next calendar month',
    },
    {
        icon: CheckCircle2,
        label: 'Recommendation mode',
        value: 'Transparent decision rules',
    },
];

function PlanningIllustration() {
    return (
        <div className="relative overflow-hidden rounded-[1.75rem] border border-slate-200 bg-energy-navy-950 p-5 text-white shadow-landing-card sm:p-7">
            <div
                className="landing-grid-pattern absolute inset-0 opacity-15"
                aria-hidden="true"
            />
            <div className="relative">
                <div className="flex items-start justify-between gap-5">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-energy-green-300 uppercase">
                            Evidence pathway
                        </p>
                        <p className="mt-2 max-w-xs text-lg leading-7 font-bold">
                            From monthly records to a reviewable planning signal
                        </p>
                    </div>
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-white/8 text-energy-green-300">
                        <Leaf className="size-5" aria-hidden="true" />
                    </span>
                </div>

                <div className="mt-8 grid grid-cols-[1fr_auto_1fr] items-center gap-2 sm:gap-4">
                    <div className="rounded-2xl border border-white/10 bg-white/[0.06] p-3 sm:p-4">
                        <Database
                            className="size-5 text-sky-300"
                            aria-hidden="true"
                        />
                        <p className="mt-5 text-xs font-bold text-slate-200">
                            Electricity + climate records
                        </p>
                    </div>
                    <div
                        className="h-px w-5 bg-gradient-to-r from-sky-300 to-energy-green-300 sm:w-10"
                        aria-hidden="true"
                    />
                    <div className="rounded-2xl border border-energy-green-300/20 bg-energy-green-300/10 p-3 sm:p-4">
                        <BarChart3
                            className="size-5 text-energy-green-300"
                            aria-hidden="true"
                        />
                        <p className="mt-5 text-xs font-bold text-slate-200">
                            Analytics + next-month estimate
                        </p>
                    </div>
                </div>

                <div className="mt-4 rounded-2xl border border-white/10 bg-white/[0.06] p-4">
                    <div className="flex items-center gap-3">
                        <span className="flex size-9 items-center justify-center rounded-xl bg-amber-400/10 text-amber-300">
                            <SunMedium className="size-4" aria-hidden="true" />
                        </span>
                        <div>
                            <p className="text-xs font-bold text-white">
                                Decision-support output
                            </p>
                            <p className="mt-1 text-xs leading-5 text-slate-400">
                                Demand classification, readiness screening, and
                                recommended points for professional review.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

export function AboutSection() {
    return (
        <section id="about" className="bg-white py-landing-section">
            <LandingContainer>
                <div className="grid gap-14 lg:grid-cols-[minmax(0,1fr)_minmax(25rem,0.78fr)] lg:items-center xl:gap-20">
                    <Reveal>
                        <SectionHeading
                            eyebrow="About the study"
                            title="Turning a complex energy-planning question into evidence that can be reviewed."
                            description="Electricity transition planning depends on understanding how consumption changes over time, what demand may look like next, and which signals warrant deeper technical investigation."
                        />
                        <div className="mt-7 max-w-3xl space-y-5 text-base leading-7 text-slate-600">
                            <p>
                                DSS Energy brings those tasks into one academic
                                platform for the Quezon City study context. It
                                summarizes monthly electricity patterns, uses
                                Ridge (penalty 0.1) to estimate the next calendar
                                month, and presents separate rule-based planning
                                recommendations.
                            </p>
                            <p>
                                The platform is designed to support analysts,
                                researchers, and institutional reviewers—not to
                                operate energy assets, determine policy on its
                                own, or guarantee a renewable-energy outcome.
                            </p>
                        </div>

                        <dl className="mt-9 grid gap-3 sm:grid-cols-3">
                            {studyHighlights.map(
                                ({ icon: Icon, label, value }) => (
                                    <div
                                        key={label}
                                        className="rounded-2xl border border-slate-200 bg-slate-50 p-4"
                                    >
                                        <Icon
                                            className="size-4 text-energy-green-700"
                                            aria-hidden="true"
                                        />
                                        <dt className="mt-4 text-xs font-semibold text-slate-500">
                                            {label}
                                        </dt>
                                        <dd className="mt-1 text-sm leading-5 font-bold text-energy-navy-950">
                                            {value}
                                        </dd>
                                    </div>
                                ),
                            )}
                        </dl>
                    </Reveal>

                    <Reveal delay={80}>
                        <PlanningIllustration />
                    </Reveal>
                </div>
            </LandingContainer>
        </section>
    );
}
