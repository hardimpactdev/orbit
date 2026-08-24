import React from "react";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { IconTile, Toast } from "@/components/launch/brand";
import { Frame, SectionDivider, useBP } from "@/components/launch/grid";
import { LaunchIcon } from "@/components/launch/icons";

const chip = (t: string) => (
    <span
        key={t}
        style={{
            fontFamily: "var(--font-mono)",
            fontSize: 11.5,
            color: "var(--chip-text)",
            background: "var(--chip-bg)",
            border: "1px solid var(--chip-border)",
            borderRadius: 6,
            padding: "3px 8px",
        }}
    >
        {t}
    </span>
);

function LibBento({ sfx }: { sfx: string }) {
    const card: React.CSSProperties = {
        border: "1px solid var(--border)",
        borderRadius: 12,
        background: "var(--card)",
        padding: 16,
        display: "grid",
        gap: 10,
        alignContent: "start",
        boxShadow: "0 4px 12px rgba(0,0,0,0.03)",
    };

    return (
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14, marginBottom: 14 }}>
            <div style={card}>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                    <Button>Get started</Button>
                    <Button variant="outline">Docs</Button>
                </div>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                    <Button variant="ghost">Ghost</Button>
                    <Button variant="destructive">Delete</Button>
                </div>
            </div>
            <div style={card}>
                <div style={{ display: "grid", gap: 6 }}>
                    <Label htmlFor={"lb-e-" + sfx}>Email address</Label>
                    <Input id={"lb-e-" + sfx} placeholder="email@example.com" readOnly />
                </div>
                <div style={{ display: "flex", alignItems: "center", gap: 10, marginTop: 4 }}>
                    <Checkbox id={"lb-r-" + sfx} defaultChecked />
                    <Label htmlFor={"lb-r-" + sfx}>Remember me</Label>
                </div>
            </div>
            <div style={{ ...card, gridColumn: "span 2" }}>
                <Toast
                    variant="success"
                    title="Application created"
                    description="myapp is ready. Auth, settings and UI included."
                    style={{ width: "100%" }}
                />
            </div>
            <div style={card}>
                <div style={{ display: "flex", gap: 10 }}>
                    <IconTile name="rocket" size="sm" />
                    <IconTile name="shield-check" size="sm" />
                    <IconTile name="zap" size="sm" />
                    <IconTile name="git-branch" size="sm" />
                </div>
            </div>
            <div style={card}>
                <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                    {["login", "2FA", "passkeys", "i18n"].map(chip)}
                </div>
            </div>
            <div
                style={{
                    ...card,
                    gridColumn: "span 2",
                    fontFamily: "var(--font-mono)",
                    fontSize: 12.5,
                    color: "var(--foreground)",
                }}
            >
                <span>
                    <span style={{ color: "var(--accent)" }}>$ </span>php artisan migrate ·{" "}
                    <span style={{ color: "var(--success)" }}>done in 0.4s</span>
                </span>
            </div>
        </div>
    );
}

export function LibraryStrip() {
    const mob = useBP() === 0;

    return (
        <div id="library">
            <SectionDivider />
            <Frame style={{ padding: mob ? "40px 16px" : "40px 32px" }}>
                <div
                    style={{
                        display: "grid",
                        gridTemplateColumns: mob ? "1fr" : "1fr 1.2fr",
                        gap: mob ? 24 : 56,
                        alignItems: "center",
                    }}
                >
                    <div style={{ display: "grid", gap: 16, padding: mob ? "20px 0 0" : "40px 0" }}>
                        <div className="mono-label" style={{ color: "var(--accent)" }}>
                            launch-ui
                        </div>
                        <h2
                            style={{
                                fontSize: "clamp(26px,5vw,34px)",
                                fontWeight: 500,
                                letterSpacing: "-0.02em",
                                lineHeight: 1.12,
                            }}
                        >
                            The UI library.
                        </h2>
                        <p
                            style={{
                                fontSize: mob ? 14.5 : 15,
                                color: "var(--muted-foreground)",
                                lineHeight: 1.65,
                                textWrap: "pretty",
                            }}
                        >
                            Buttons, forms, menus, toasts, the app sidebar, all built on Base UI and
                            shadcn and restyled for the dark. Every component is optimized for
                            Inertia, with typed links, forms and page props out of the box. Use it
                            inside the starter kit or drop it into any Laravel + React app.
                        </p>
                        <div>
                            <Button variant="outline">
                                Browse components <LaunchIcon name="arrow-right" size={14} />
                            </Button>
                        </div>
                    </div>
                    <div
                        style={{
                            marginRight: mob ? -16 : -32,
                            marginLeft: mob ? -16 : 0,
                            WebkitMaskImage: mob
                                ? "none"
                                : "linear-gradient(90deg,transparent 0,#000 26%)",
                            maskImage: mob
                                ? "none"
                                : "linear-gradient(90deg,transparent 0,#000 26%)",
                        }}
                    >
                        <div
                            style={{
                                position: "relative",
                                height: mob ? 300 : 460,
                                overflow: "hidden",
                                WebkitMaskImage:
                                    "linear-gradient(180deg,transparent 0,#000 14%,#000 78%,transparent 100%)",
                                maskImage:
                                    "linear-gradient(180deg,transparent 0,#000 14%,#000 78%,transparent 100%)",
                            }}
                        >
                            <div style={{ perspective: "1100px" }}>
                                <div
                                    style={{
                                        transform:
                                            "rotateX(16deg) rotateY(-10deg) rotateZ(4deg) scale(1.04)",
                                        transformOrigin: "50% 20%",
                                    }}
                                >
                                    <div style={{ animation: "libscroll 34s linear infinite" }}>
                                        <LibBento sfx="a" />
                                        <div aria-hidden="true" inert>
                                            <LibBento sfx="b" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Frame>
        </div>
    );
}
