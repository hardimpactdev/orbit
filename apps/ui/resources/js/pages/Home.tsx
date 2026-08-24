import { Head } from "@inertiajs/react";
import { SiteHeader } from "@/components/launch/site-header";
import { Hero } from "@/components/launch/hero";
import { HowItWorks } from "@/components/launch/how-it-works";
import { Features } from "@/components/launch/features";
import { LibraryStrip } from "@/components/launch/library-strip";
import { StackSection } from "@/components/launch/stack-section";
import { CTA } from "@/components/launch/cta";
import { SiteFooter } from "@/components/launch/site-footer";

export default function Home() {
    return (
        <div
            id="top"
            className="min-h-screen bg-background text-foreground antialiased selection:bg-accent selection:text-primary-foreground"
        >
            <Head title="Ship your next idea in minutes" />
            <SiteHeader />
            <main>
                <Hero />
                <HowItWorks />
                <Features />
                <LibraryStrip />
                <StackSection />
                <CTA />
            </main>
            <SiteFooter />
        </div>
    );
}
