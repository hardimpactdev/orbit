import React from "react";
import { Toast } from "@/components/launch/brand";
import { CornerBrackets } from "@/components/launch/corner-brackets";
import { Frame, SectionDivider, useBP } from "@/components/launch/grid";
import { LaunchIcon } from "@/components/launch/icons";
import { useAppearance } from "@/hooks/use-appearance";

export function CTA() {
    const { resolvedAppearance } = useAppearance();
    const isDark = resolvedAppearance === "dark";
    const bp = useBP();
    const mob = bp === 0;
    const [copied, setCopied] = React.useState(false);
    const [chipHov, setChipHov] = React.useState(false);
    const prompt = "Start a new project based on launch.nckrtl.com";

    const copy = () => {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(prompt).catch(() => {});
        }
        setCopied(true);
        setTimeout(() => setCopied(false), 2200);
    };

    const pinstripe = `url("data:image/svg+xml,${encodeURIComponent(
        `<svg xmlns='http://www.w3.org/2000/svg' width='4' height='4'><path d='M-1,1 l2,-2 M0,4 l4,-4 M3,5 l2,-2' stroke='${
            chipHov
                ? isDark
                    ? "rgba(251,59,0,0.32)"
                    : "rgba(251,59,0,0.22)"
                : isDark
                  ? "rgba(255,255,255,0.06)"
                  : "rgba(0,0,0,0.06)"
        }' stroke-width='1'/></svg>`,
    )}")`;

    return (
        <div>
            <SectionDivider />
            <Frame style={{ padding: mob ? "64px 16px" : "96px 32px", textAlign: "center" }}>
                <h2
                    style={{
                        fontSize: "clamp(30px,7vw,44px)",
                        fontWeight: 500,
                        letterSpacing: "-0.03em",
                        lineHeight: 1.05,
                    }}
                >
                    Give your next idea
                    <br />a head start.
                </h2>
                <p
                    style={{
                        fontSize: mob ? 15 : 16,
                        color: "var(--muted-foreground)",
                        marginTop: 16,
                    }}
                >
                    One prompt to start. Paste it into your agent chat.
                </p>
                <button
                    type="button"
                    onClick={copy}
                    title="Copy prompt"
                    aria-label={`Copy prompt: ${prompt}`}
                    onMouseEnter={() => setChipHov(true)}
                    onMouseLeave={() => setChipHov(false)}
                    onFocus={() => setChipHov(true)}
                    onBlur={() => setChipHov(false)}
                    className="focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--primary)]"
                    style={{
                        position: "relative",
                        display: "inline-flex",
                        alignItems: mob ? "flex-start" : "center",
                        textAlign: "left",
                        maxWidth: "100%",
                        gap: mob ? 9 : 12,
                        marginTop: mob ? 26 : 32,
                        fontFamily: "var(--font-mono)",
                        fontSize: mob ? 11.5 : 13,
                        lineHeight: 1.5,
                        color: "var(--foreground)",
                        backgroundColor: "transparent",
                        backgroundImage: pinstripe,
                        backgroundRepeat: "repeat",
                        border: `1px solid ${chipHov ? "var(--accent-glow)" : "var(--grid-line)"}`,
                        borderRadius: 2,
                        padding: mob ? "12px 14px" : "14px 18px",
                        cursor: "pointer",
                        whiteSpace: mob ? "normal" : "nowrap",
                        boxShadow: "none",
                    }}
                >
                    <CornerBrackets
                        offset={-1}
                        size={5}
                        radius={2}
                        color="var(--primary)"
                        active={true}
                    />
                    <span style={{ color: "var(--accent)" }}>❯</span>
                    <span>"{prompt}"</span>
                    <LaunchIcon
                        name={copied ? "check" : "copy"}
                        size={15}
                        style={{
                            color: copied ? "var(--success)" : "var(--icon-muted)",
                            flex: "none",
                        }}
                    />
                </button>
                {copied && (
                    <div
                        style={{
                            position: "fixed",
                            bottom: 28,
                            left: "50%",
                            transform: "translateX(-50%)",
                            zIndex: 100,
                            animation: "toastup .3s var(--ease-out) both",
                        }}
                    >
                        <Toast variant="success" title="Copied instructions to clipboard" />
                    </div>
                )}
            </Frame>
        </div>
    );
}
