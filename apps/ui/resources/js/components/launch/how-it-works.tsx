import React from "react";
import { IconTile } from "@/components/launch/brand";
import { CellMark, Frame, SectionDivider, SectionHeader, useBP } from "@/components/launch/grid";

const MOB_MARKS_MAP: Record<string, string> = { tl: "tbr", tr: "tbl" };

function Step({
    n,
    icon,
    title,
    children,
    marks,
    bl,
}: {
    n: string;
    icon: string;
    title: string;
    children: React.ReactNode;
    marks?: Record<string, string>;
    bl?: boolean;
}) {
    const [hov, setHov] = React.useState(false);
    const mob = useBP() === 0;
    const mk = mob ? MOB_MARKS_MAP : marks;

    return (
        <div
            onMouseEnter={() => setHov(true)}
            onMouseLeave={() => setHov(false)}
            style={{
                position: "relative",
                zIndex: hov ? 3 : 1,
                minWidth: 0,
                borderLeft: bl && !mob ? "1px solid var(--grid-line)" : undefined,
                padding: mob ? "30px 16px" : "40px 36px",
                borderTop: "1px solid var(--grid-line)",
                display: "grid",
                gap: mob ? 22 : 28,
                alignContent: "start",
                background: hov ? "var(--card-hover-bg)" : "transparent",
                transition: "background .2s var(--ease-out)",
            }}
        >
            {mk &&
                Object.entries(mk).map(([at, arms]) => (
                    <CellMark key={at} at={at} arms={arms} on={hov} />
                ))}
            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
                <IconTile name={icon} size="lg" active={hov} />
                <span
                    style={{
                        fontFamily: "var(--font-mono)",
                        fontSize: 32,
                        fontWeight: 700,
                        color: "var(--primary)",
                        transition: "color var(--dur-fast) var(--ease-out)",
                        lineHeight: 1,
                    }}
                >
                    {n}
                </span>
            </div>
            <div style={{ display: "grid", gap: 6 }}>
                <h3
                    style={{
                        fontSize: 18,
                        fontWeight: 500,
                        letterSpacing: "-0.01em",
                        color: hov ? "var(--primary)" : "var(--foreground)",
                        transition: "color var(--dur-fast) var(--ease-out)",
                    }}
                >
                    {title}
                </h3>
                <p style={{ fontSize: 14, color: "var(--muted-foreground)", lineHeight: 1.6 }}>
                    {children}
                </p>
            </div>
        </div>
    );
}

export function HowItWorks() {
    const mob = useBP() === 0;

    return (
        <div id="how">
            <SectionDivider />
            <Frame style={{ padding: mob ? "56px 16px" : "80px 32px" }}>
                <SectionHeader
                    eyebrow="How it works"
                    title="One prompt. That's it."
                    sub="Point your AI at Launch. It scaffolds the project, asks the right questions, and gets out of the way."
                />
                <div
                    style={{
                        margin: mob ? "0 -16px -56px" : "0 -32px -80px",
                        display: "grid",
                        gridTemplateColumns: mob ? "1fr" : "repeat(3,1fr)",
                    }}
                >
                    <Step
                        n="01"
                        icon="terminal"
                        title="Start"
                        marks={{ tl: "tbr", tr: "lrb", bl: "tblr", br: "lrt" }}
                    >
                        Tell your AI to start from Launch. It asks what you need, full app or bare
                        minimum, Filament or not, and then scaffolds Laravel 13, React 19, Inertia
                        v3 and Tailwind 4.
                    </Step>
                    <Step
                        n="02"
                        icon="blocks"
                        title="Build"
                        bl
                        marks={{ tl: "lrb", tr: "lrb", bl: "lrt", br: "lrt" }}
                    >
                        Compose screens from launch-ui: forms, menus, sidebar shell, settings. Base
                        UI and shadcn underneath.
                    </Step>
                    <Step
                        n="03"
                        icon="rocket"
                        title="Ship"
                        bl
                        marks={{ tl: "lrb", tr: "tbl", bl: "tblr", br: "tblr" }}
                    >
                        Auth, 2FA, passkeys and type-safe routes are in from the start. Deploy
                        anywhere Laravel runs.
                    </Step>
                </div>
            </Frame>
        </div>
    );
}
