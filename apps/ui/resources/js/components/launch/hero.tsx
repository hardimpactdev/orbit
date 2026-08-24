import React from "react";
import { Button } from "@/components/ui/button";
import { MARK_PATH } from "@/components/launch/brand";
import { LaunchNowButton } from "@/components/launch/cta-button";
import { Frame, useBP } from "@/components/launch/grid";
import { LaunchIcon } from "@/components/launch/icons";
import { Marquee } from "@/components/launch/marquee";
import { AgentTerminal } from "@/components/launch/agent-terminal";

const TECHS: [string | null, string][] = [
    ["laravel", "Laravel"],
    ["react", "React"],
    ["inertia", "Inertia"],
    ["tailwindcss", "Tailwind"],
    ["vite", "Vite"],
    ["typescript", "TypeScript"],
    ["bun", "Bun"],
    ["shadcnui", "shadcn"],
    ["filament", "Filament"],
    ["rolldown", "Rolldown"],
    ["oxc", "Oxc"],
    ["larastan", "Larastan"],
    ["pestphp", "Pest"],
];

function TechMarquee({ mob }: { mob: boolean }) {
    const chipStyle: React.CSSProperties = {
        display: "inline-flex",
        alignItems: "center",
        gap: 8,
        flex: "none",
        padding: "10px 20px",
        background: "var(--marquee-chip-bg)",
        borderRight: "1px solid var(--grid-line)",
    };

    const renderChips = (keyPrefix: string) =>
        TECHS.map(([f, name], i) => (
            <span
                key={`${keyPrefix}-${i}`}
                style={chipStyle}
                aria-hidden={keyPrefix !== "a" ? true : undefined}
            >
                {f && (
                    <img
                        src={`/assets/logos/${f}.svg`}
                        width="12"
                        height="12"
                        alt=""
                        style={{ opacity: 0.75 }}
                    />
                )}
                <span className="mono-label" style={{ fontSize: 10 }}>
                    {name}
                </span>
            </span>
        ));

    return (
        <Marquee
            duration="60s"
            fadeWidth={130}
            maskGradient="linear-gradient(90deg, transparent, #000 15%, #000 85%, transparent)"
            style={{
                margin: mob ? "-20px -16px 44px" : "-32px -32px 76px",
                borderBottom: "1px solid var(--grid-line)",
            }}
        >
            {renderChips("a")}
            {renderChips("b")}
        </Marquee>
    );
}

export function Hero() {
    const bp = useBP();
    const mob = bp === 0;

    return (
        <Frame
            crosses={["bl", "br"]}
            style={{ padding: mob ? "20px 16px 56px" : "32px 32px 88px", textAlign: "center" }}
        >
            <div
                style={{
                    position: "absolute",
                    left: "50%",
                    transform: "translateX(-50%)",
                    top: 0,
                    bottom: 0,
                    width: "100vw",
                    overflow: "hidden",
                    pointerEvents: "none",
                }}
            >
                <svg
                    viewBox="0 0 2524 1160"
                    preserveAspectRatio="xMidYMax meet"
                    style={{
                        position: "absolute",
                        left: 0,
                        bottom: 0,
                        width: "103.11vw",
                        height: "auto",
                    }}
                >
                    <path d={MARK_PATH} fill="var(--mark-watermark)" />
                </svg>
            </div>
            <div
                style={{
                    position: "absolute",
                    inset: 0,
                    backdropFilter: "blur(12px)",
                    WebkitBackdropFilter: "blur(12px)",
                    pointerEvents: "none",
                }}
            />
            <div style={{ position: "relative" }}>
                <TechMarquee mob={mob} />
                <h1
                    style={{
                        position: "relative",
                        fontSize: "clamp(32px,7vw,76px)",
                        fontWeight: 500,
                        letterSpacing: "-0.03em",
                        lineHeight: 1.05,
                    }}
                >
                    <span
                        style={{
                            backgroundImage: "var(--title-gradient)",
                            WebkitBackgroundClip: "text",
                            backgroundClip: "text",
                            color: "transparent",
                            WebkitBoxDecorationBreak: "clone",
                            boxDecorationBreak: "clone",
                            paddingRight: "0.15em",
                            marginRight: "-0.15em",
                        }}
                    >
                        Launch your next idea
                        <br />
                        faster than ever
                    </span>
                    <span
                        className="cursor"
                        style={{
                            color: "var(--primary)",
                            animation: "blink 1.1s steps(1) infinite",
                        }}
                    >
                        _
                    </span>
                </h1>
                <p
                    style={{
                        fontSize: mob ? 15.5 : 17,
                        color: "var(--muted-foreground)",
                        lineHeight: 1.6,
                        maxWidth: 560,
                        margin: "22px auto 0",
                        textWrap: "pretty",
                    }}
                >
                    Launch is a toolkit that gets your ideas up and running faster, mainly through a
                    starter kit and a UI library for Laravel.
                </p>
                <div
                    style={{
                        display: "flex",
                        gap: mob ? 10 : 14,
                        flexWrap: "wrap",
                        justifyContent: "center",
                        marginTop: mob ? 26 : 34,
                    }}
                >
                    <LaunchNowButton />
                    <Button
                        size="lg"
                        variant="outline"
                        style={{ padding: "0 18px" }}
                        nativeButton={false}
                        render={
                            <a
                                href="https://github.com/nckrtl/launch-starter-kit"
                                target="_blank"
                                rel="noreferrer"
                            />
                        }
                    >
                        Star on GitHub <LaunchIcon name="arrow-up-right" size={14} />
                    </Button>
                </div>
                <div style={{ position: "relative", margin: mob ? "36px 0 0" : "56px 0 0" }}>
                    <div
                        style={{
                            position: "absolute",
                            left: "50%",
                            top: "48%",
                            transform: "translate(-50%,-50%)",
                            width: mob ? 300 : 620,
                            height: 340,
                            borderRadius: "50%",
                            background: "var(--accent)",
                            opacity: 0.08,
                            filter: "blur(110px)",
                            pointerEvents: "none",
                        }}
                    />
                    <AgentTerminal />
                </div>
            </div>
        </Frame>
    );
}
