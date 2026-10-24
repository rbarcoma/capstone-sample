import { Head, usePage } from '@inertiajs/react';
import { lazy, Suspense } from 'react';

import { AboutSection } from '@/components/landing/about-section';
import { CapabilitiesSection } from '@/components/landing/capabilities-section';
import { ContactSection } from '@/components/landing/contact-section';
import { EnergySnapshotSkeleton } from '@/components/landing/energy-snapshot-skeleton';
import { HeroSection } from '@/components/landing/hero-section';
import type { LandingAnalyticsContract } from '@/components/landing/landing-data';
import { resolveLandingSnapshot } from '@/components/landing/landing-data';
import { LandingFooter } from '@/components/landing/landing-footer';
import { LandingHeader } from '@/components/landing/landing-header';
import { MethodologySection } from '@/components/landing/methodology-section';
import { TransitionSection } from '@/components/landing/transition-section';
import { WorkflowSection } from '@/components/landing/workflow-section';
import { useBrowserCloseLogout } from '@/hooks/use-browser-close-logout';

const EnergySnapshotSection = lazy(
    () => import('@/components/landing/energy-snapshot-section'),
);

type WelcomeProps = {
    canRegister?: boolean;
    landingAnalytics?: LandingAnalyticsContract;
};

export default function Welcome({
    canRegister = true,
    landingAnalytics,
}: WelcomeProps) {
    useBrowserCloseLogout();

    const { auth } = usePage().props;
    const isAuthenticated = Boolean(auth.user);
    const snapshot = resolveLandingSnapshot(landingAnalytics);

    return (
        <>
            <Head title="DSS Energy — Renewable Energy Decision Support">
                <meta
                    head-key="description"
                    name="description"
                    content="DSS Energy is an academic Quezon City decision-support platform for electricity-consumption analytics, next-month forecasting, and transparent renewable-energy planning recommendations."
                />
                <meta
                    head-key="og-title"
                    property="og:title"
                    content="DSS Energy — Data-driven renewable-energy planning for Quezon City"
                />
                <meta
                    head-key="og-description"
                    property="og:description"
                    content="Explore consumption analytics, Ridge (penalty 0.1) next-month forecasting, and transparent rule-based decision support in an academic research platform."
                />
                <meta head-key="og-type" property="og:type" content="website" />
                <meta
                    head-key="theme-color"
                    name="theme-color"
                    content="#071827"
                />
            </Head>

            <div className="landing-page min-h-screen overflow-x-clip bg-white font-landing text-energy-navy-950 antialiased">
                <a
                    href="#main-content"
                    className="fixed top-3 left-3 z-[100] -translate-y-20 rounded-lg bg-white px-4 py-3 text-sm font-bold text-energy-navy-950 shadow-xl transition-transform outline-none focus:translate-y-0 focus:ring-2 focus:ring-energy-green-500"
                >
                    Skip to main content
                </a>

                <LandingHeader
                    canRegister={canRegister}
                    isAuthenticated={isAuthenticated}
                />

                <main id="main-content">
                    <HeroSection
                        canRegister={canRegister}
                        isAuthenticated={isAuthenticated}
                        snapshot={snapshot}
                    />
                    <Suspense fallback={<EnergySnapshotSkeleton />}>
                        <EnergySnapshotSection snapshot={snapshot} />
                    </Suspense>
                    <AboutSection />
                    <WorkflowSection />
                    <CapabilitiesSection isAuthenticated={isAuthenticated} />
                    <MethodologySection />
                    <TransitionSection />
                    <ContactSection />
                </main>

                <LandingFooter />
            </div>
        </>
    );
}
