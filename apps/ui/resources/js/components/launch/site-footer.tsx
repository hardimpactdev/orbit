import React from "react";
import { Logo } from "@/components/launch/brand";
import { Frame, SectionDivider, useBP } from "@/components/launch/grid";

const FOOTER_LINKS: [string, string][] = [
    ["GitHub", "https://github.com/nckrtl/launch-starter-kit"],
    ["Docs", "/create.md"],
    ["launch-ui", "#library"],
    ["nckrtl.com", "https://nckrtl.com"],
];

export function SiteFooter() {
    const bp = useBP();
    const mob = bp === 0;

    return (
        <footer>
            <SectionDivider />
            <Frame
                style={{
                    padding: mob ? "22px 16px" : "32px",
                    display: "flex",
                    alignItems: "center",
                    flexWrap: "wrap",
                    gap: mob ? 18 : 24,
                }}
            >
                <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                    <Logo variant="mark" height={18} />
                </div>
                <nav
                    style={{
                        marginLeft: mob ? 0 : "auto",
                        width: mob ? "100%" : undefined,
                        display: "flex",
                        flexWrap: "wrap",
                        gap: mob ? 16 : 22,
                    }}
                >
                    {FOOTER_LINKS.map(([t, url]) => (
                        <a
                            key={t}
                            href={url}
                            target={url.startsWith("http") ? "_blank" : undefined}
                            rel={url.startsWith("http") ? "noreferrer" : undefined}
                            style={{
                                fontFamily: "var(--font-mono)",
                                fontSize: 12,
                                color: "var(--muted-foreground)",
                                textDecoration: "none",
                            }}
                            className="hover:text-accent transition-colors duration-150"
                        >
                            {t}
                        </a>
                    ))}
                </nav>
            </Frame>
            <div className="rule-h" />
            <Frame crosses={["tl", "tr"]} style={{ padding: mob ? "14px 16px" : "16px 32px" }}>
                <span
                    style={{
                        fontFamily: "var(--font-mono)",
                        fontSize: 11,
                        color: "var(--muted-foreground)",
                    }}
                >
                    MIT licensed · built by{" "}
                    <a
                        href="https://nckrtl.com"
                        target="_blank"
                        rel="noreferrer"
                        style={{
                            color: "inherit",
                            textDecoration: "none",
                        }}
                        className="hover:text-accent transition-colors duration-150"
                    >
                        nckrtl
                    </a>
                </span>
            </Frame>
        </footer>
    );
}
