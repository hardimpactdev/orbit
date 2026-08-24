import React from "react";
import { CornerOrnament, type CornerPosition } from "@/components/launch/corner-brackets";
import { useBP } from "@/components/launch/grid";
import { LaunchIcon } from "@/components/launch/icons";
import { useAppearance } from "@/hooks/use-appearance";

const AT_PROMPT = "Start a new project based on launch.nckrtl.com";
const AT_DEPLOY = "laravel cloud deploy";
const AT_FRAMES = ["⠋", "⠙", "⠹", "⠸", "⠼", "⠴", "⠦", "⠧", "⠇", "⠏"];

function ATCorner({ at }: { at: string }) {
    return (
        <CornerOrnament
            at={at as CornerPosition}
            offset={0}
            size={5}
            radius={2}
            color="var(--accent)"
        />
    );
}

function ATMark({ n, show, r = 8 }: { n: number; show: boolean; r?: number }) {
    return (
        <span
            style={{
                position: "absolute",
                inset: -6,
                border: "1px dashed var(--accent)",
                borderRadius: r,
                opacity: show ? 1 : 0,
                transform: show ? "scale(1)" : "scale(.85)",
                transition: "opacity .25s, transform .25s var(--ease-out)",
                pointerEvents: "none",
            }}
        >
            <span
                style={{
                    position: "absolute",
                    top: -8,
                    right: -8,
                    width: 15,
                    height: 15,
                    borderRadius: 99,
                    background: "var(--accent)",
                    color: "#fff",
                    fontSize: 9.5,
                    display: "flex",
                    alignItems: "center",
                    justifyContent: "center",
                    fontFamily: "var(--font-mono)",
                }}
            >
                {n}
            </span>
        </span>
    );
}

function ATNote({ text, side }: { text: string; side?: string }) {
    const pos = side === "right" ? { right: -6 } : { left: -6 };
    return (
        <span
            style={{
                position: "absolute",
                top: "calc(100% + 12px)",
                ...pos,
                width: 220,
                zIndex: 6,
                display: "block",
                background: "var(--dropdown-bg)",
                border: "1px solid var(--dropdown-border)",
                borderRadius: 8,
                padding: "7px 10px",
                fontFamily: "var(--font-mono)",
                fontSize: 11.5,
                lineHeight: 1.5,
                color: "var(--foreground)",
                boxShadow: "var(--shadow-overlay)",
                whiteSpace: "normal",
                textAlign: "left",
            }}
        >
            {text}
            <span
                style={{
                    display: "inline-block",
                    width: 6,
                    height: 11,
                    marginLeft: 2,
                    verticalAlign: "-1px",
                    background: "var(--foreground)",
                    animation: "blink 1.1s steps(1) infinite",
                }}
            />
            <span
                style={{
                    display: "block",
                    marginTop: 5,
                    fontSize: 9.5,
                    color: "var(--muted-foreground)",
                }}
            >
                ↵ enter to send
            </span>
        </span>
    );
}

function ATTabs({ tab, onTab, mob }: { tab: number; onTab: (i: number) => void; mob: boolean }) {
    return (
        <span style={{ flex: 1, display: "flex", justifyContent: "center", gap: mob ? 2 : 6 }}>
            {["Scaffold", "Build", "Deploy"].map((t, i) => (
                <button
                    key={t}
                    onClick={() => onTab(i)}
                    style={{
                        border: 0,
                        cursor: "pointer",
                        fontFamily: "var(--font-mono)",
                        fontSize: mob ? 10 : 11,
                        fontWeight: 700,
                        letterSpacing: ".04em",
                        padding: mob ? "3px 7px" : "3px 10px",
                        borderRadius: 5,
                        background: tab === i ? "var(--accent)" : "none",
                        color: tab === i ? "#ffffff" : "var(--muted-foreground)",
                        transition: "color var(--dur-fast), background var(--dur-fast)",
                    }}
                >
                    <span
                        style={{
                            color: "inherit",
                            marginRight: mob ? 4 : 6,
                            opacity: tab === i ? 0.8 : 1,
                        }}
                    >
                        0{i + 1}
                    </span>
                    {t}
                </button>
            ))}
        </span>
    );
}

function ATScrollback({
    hist,
    phase,
    tick,
    mob,
}: {
    hist: any[];
    phase: any;
    tick: number;
    mob: boolean;
}) {
    const row: React.CSSProperties = { padding: "1px 0" };
    return (
        <div
            style={{
                flex: 1,
                minHeight: 0,
                padding: mob ? "12px 12px" : "14px 16px",
                display: "flex",
                flexDirection: "column",
                justifyContent: "flex-end",
                overflow: "hidden",
                whiteSpace: "pre-wrap",
                wordBreak: "break-word",
            }}
        >
            {hist.map((l: any, i: number) => {
                if (l.t === "user")
                    return (
                        <div key={i} style={{ ...row, color: "var(--foreground)" }}>
                            <span style={{ color: "var(--muted-foreground)" }}>&gt; </span>
                            {l.text}
                        </div>
                    );
                if (l.t === "cmd")
                    return (
                        <div key={i} style={{ ...row, color: "var(--foreground)" }}>
                            <span style={{ color: "var(--accent)" }}>❯ </span>
                            {l.text}
                        </div>
                    );
                if (l.t === "pick")
                    return (
                        <div key={i} style={row}>
                            <span style={{ color: "var(--success)" }}>✔ </span>
                            <span style={{ color: "var(--muted-foreground)" }}>{l.q} · </span>
                            <span style={{ color: "var(--foreground)" }}>{l.a}</span>
                        </div>
                    );
                if (l.t === "ok")
                    return (
                        <div key={i} style={{ ...row, color: "var(--foreground)" }}>
                            <span style={{ color: "var(--success)" }}>{l.text.slice(0, 1)}</span>
                            {l.text.slice(1)}
                        </div>
                    );
                if (l.t === "ready")
                    return (
                        <div key={i} style={{ ...row, color: "var(--accent)" }}>
                            {l.text}
                        </div>
                    );
                return (
                    <div
                        key={i}
                        style={{
                            ...row,
                            color: l.dim ? "var(--muted-foreground)" : "var(--foreground)",
                        }}
                    >
                        {l.text}
                    </div>
                );
            })}
            {phase.mode === "spin" && (
                <div style={{ ...row, color: "var(--muted-foreground)" }}>
                    <span style={{ color: "var(--accent)" }}>
                        {AT_FRAMES[tick % AT_FRAMES.length]}{" "}
                    </span>
                    {phase.label}
                </div>
            )}
        </div>
    );
}

function ATBottomBar({
    phase,
    cursor,
    mob,
}: {
    phase: any;
    cursor: React.ReactNode;
    mob: boolean;
}) {
    return (
        <div
            style={{
                flex: "none",
                borderTop: "1px solid var(--border)",
                padding: mob ? "9px 12px" : "10px 16px",
                background: "var(--surface-raised)",
                backdropFilter: "blur(6px)",
                WebkitBackdropFilter: "blur(6px)",
                whiteSpace: "pre-wrap",
                wordBreak: "break-word",
            }}
        >
            {phase.mode === "select" ? (
                <div>
                    <div style={{ color: "var(--foreground)", marginBottom: 6 }}>
                        <span style={{ color: "var(--accent)" }}>? </span>
                        {phase.q}
                    </div>
                    {phase.options.map((o: string, i: number) => (
                        <div
                            key={o}
                            style={{
                                borderRadius: 5,
                                padding: "1px 8px",
                                color:
                                    i === phase.sel ? "var(--accent)" : "var(--muted-foreground)",
                                background: i === phase.sel ? "var(--accent-faint)" : "none",
                            }}
                        >
                            {i === phase.sel ? "❯ " : "  "}
                            {o}
                        </div>
                    ))}
                    <div style={{ marginTop: 6, fontSize: 11, color: "var(--muted-foreground)" }}>
                        ↑↓ to move · enter to confirm
                    </div>
                </div>
            ) : (
                <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                    <span style={{ color: "var(--accent)" }}>❯</span>
                    <span
                        style={{
                            color: "var(--foreground)",
                            display: "inline-flex",
                            alignItems: "center",
                        }}
                    >
                        {phase.mode === "typing" ? phase.text.slice(0, phase.ch) : ""}
                        {cursor}
                    </span>
                </div>
            )}
        </div>
    );
}

function ATChat({ msgs, cursor }: { msgs: any[]; cursor: React.ReactNode }) {
    return (
        <div style={{ height: "100%", display: "flex", flexDirection: "column", minWidth: 0 }}>
            <div
                style={{
                    flex: 1,
                    minHeight: 0,
                    padding: "12px 14px",
                    display: "flex",
                    flexDirection: "column",
                    justifyContent: "flex-end",
                    overflow: "hidden",
                }}
            >
                {msgs.map((m: any, i: number) => {
                    if (m.t === "sys")
                        return (
                            <div
                                key={i}
                                style={{
                                    fontSize: 11.5,
                                    color: "var(--muted-foreground)",
                                    margin: "4px 0",
                                }}
                            >
                                {m.text}
                            </div>
                        );
                    if (m.t === "user")
                        return (
                            <div
                                key={i}
                                style={{
                                    display: "flex",
                                    gap: 7,
                                    alignItems: "center",
                                    fontSize: 12,
                                    color: "var(--foreground)",
                                    margin: "5px 0",
                                }}
                            >
                                <span
                                    style={{
                                        flex: "none",
                                        width: 14,
                                        height: 14,
                                        borderRadius: 99,
                                        background: "var(--accent)",
                                        color: "#ffffff",
                                        fontSize: 9,
                                        lineHeight: 1,
                                        fontFamily: "var(--font-mono)",
                                        display: "inline-flex",
                                        alignItems: "center",
                                        justifyContent: "center",
                                    }}
                                >
                                    {m.n}
                                </span>
                                <span>{m.text}</span>
                            </div>
                        );
                    if (m.t === "ok")
                        return (
                            <div
                                key={i}
                                style={{
                                    fontSize: 12,
                                    color: "var(--muted-foreground)",
                                    margin: "5px 0",
                                }}
                            >
                                <span style={{ color: "var(--success)" }}>✓ </span>
                                {m.text}
                            </div>
                        );
                    return (
                        <div
                            key={i}
                            style={{ fontSize: 12, color: "var(--foreground)", margin: "5px 0" }}
                        >
                            <span style={{ color: "var(--accent)" }}>› </span>
                            {m.text}
                        </div>
                    );
                })}
            </div>
            <div
                style={{
                    flex: "none",
                    borderTop: "1px solid var(--border)",
                    padding: "9px 14px",
                    display: "flex",
                    alignItems: "center",
                    gap: 8,
                    background: "var(--surface-raised)",
                    backdropFilter: "blur(6px)",
                    WebkitBackdropFilter: "blur(6px)",
                }}
            >
                <span style={{ color: "var(--accent)" }}>❯</span>
                {cursor}
            </div>
        </div>
    );
}

function ATPreview({ bs }: { bs: any }) {
    const link: React.CSSProperties = { fontSize: 11, color: "var(--muted-foreground)" };
    const seg: React.CSSProperties = {
        display: "flex",
        alignItems: "center",
        gap: 5,
        padding: "0 9px",
        borderRight: "1px solid var(--border)",
        whiteSpace: "nowrap",
    };
    return (
        <div
            style={{
                height: "100%",
                display: "flex",
                flexDirection: "column",
                minWidth: 0,
                background: "var(--card)",
                color: "var(--foreground)",
                fontFamily: "var(--font-sans)",
            }}
        >
            <div
                style={{
                    flex: "none",
                    height: 30,
                    display: "flex",
                    alignItems: "center",
                    justifyContent: "center",
                    borderBottom: "1px solid var(--border)",
                    background: "var(--surface-raised)",
                }}
            >
                <span
                    style={{
                        fontSize: 10.5,
                        color: "var(--muted-foreground)",
                        background: "var(--card)",
                        border: "1px solid var(--border)",
                        borderRadius: 99,
                        padding: "2px 12px",
                    }}
                >
                    myapp.test
                </span>
            </div>
            <div
                style={{ flex: 1, padding: "16px 22px", position: "relative", overflow: "hidden" }}
            >
                <div style={{ display: "flex", alignItems: "center", gap: 16 }}>
                    <span style={{ display: "flex", alignItems: "center", gap: 6 }}>
                        <span
                            style={{
                                width: 9,
                                height: 9,
                                borderRadius: 2,
                                background: "var(--accent)",
                            }}
                        />
                        <span style={{ fontSize: 12, fontWeight: 600, color: "var(--foreground)" }}>
                            hausmarkt
                        </span>
                    </span>
                    <span style={{ display: "flex", gap: 14 }}>
                        <span style={link}>Homes</span>
                        <span style={link}>Agents</span>
                        <span style={link}>Mortgages</span>
                        <span style={link}>About</span>
                    </span>
                    <span
                        style={{
                            position: "relative",
                            display: "inline-block",
                            marginLeft: "auto",
                        }}
                    >
                        <span
                            style={{
                                display: "inline-block",
                                fontSize: 11.5,
                                padding: "5px 12px",
                                borderRadius: 7,
                                border: "1px solid",
                                transition: "all .35s var(--ease-out)",
                                background: bs.btn ? "var(--accent)" : "var(--surface-raised)",
                                borderColor: bs.btn ? "transparent" : "var(--border-strong)",
                                color: bs.btn ? "#ffffff" : "var(--foreground)",
                                boxShadow: bs.btn ? "var(--glow-accent)" : "none",
                            }}
                        >
                            List your home
                        </span>
                        <ATMark n={1} show={bs.m1} r={9} />
                        {bs.note && bs.note.t === 1 && <ATNote text={bs.note.text} side="right" />}
                    </span>
                </div>
                <div style={{ marginTop: 18 }}>
                    <span style={{ position: "relative", display: "inline-block" }}>
                        <span
                            style={{
                                fontSize: 22,
                                fontWeight: 600,
                                letterSpacing: "-0.02em",
                                color: "var(--foreground)",
                            }}
                        >
                            {bs.h2 ? "Find your next place, faster" : "Find your place"}
                        </span>
                        <ATMark n={2} show={bs.m2} r={6} />
                        {bs.note && bs.note.t === 2 && <ATNote text={bs.note.text} />}
                    </span>
                    <div style={{ fontSize: 12, color: "var(--muted-foreground)", marginTop: 5 }}>
                        4.812 homes across the Netherlands, updated daily.
                    </div>
                    <div
                        style={{
                            display: "flex",
                            alignItems: "center",
                            gap: 8,
                            marginTop: 12,
                            maxWidth: 440,
                        }}
                    >
                        <span
                            style={{
                                flex: 1,
                                display: "flex",
                                alignItems: "center",
                                gap: 7,
                                height: 32,
                                border: "1px solid var(--border)",
                                borderRadius: 8,
                                background: "var(--card)",
                                padding: "0 11px",
                                fontSize: 11.5,
                                color: "var(--muted-foreground)",
                            }}
                        >
                            <LaunchIcon
                                name="search"
                                size={12}
                                style={{ color: "var(--faint-foreground)" }}
                            />
                            Amsterdam, Netherlands
                        </span>
                        <span
                            style={{
                                height: 32,
                                display: "inline-flex",
                                alignItems: "center",
                                padding: "0 14px",
                                borderRadius: 8,
                                background: "var(--foreground)",
                                color: "var(--background)",
                                fontSize: 11.5,
                                fontWeight: 500,
                            }}
                        >
                            Search
                        </span>
                    </div>
                    <div style={{ display: "flex", gap: 6, marginTop: 12 }}>
                        {["Buy", "Rent", "New builds", "Sold"].map((c, i) => (
                            <span
                                key={c}
                                style={{
                                    fontSize: 10.5,
                                    padding: "3px 10px",
                                    borderRadius: 99,
                                    border: "1px solid",
                                    borderColor: i === 0 ? "var(--foreground)" : "var(--border)",
                                    background: i === 0 ? "var(--foreground)" : "var(--card)",
                                    color:
                                        i === 0 ? "var(--background)" : "var(--muted-foreground)",
                                }}
                            >
                                {c}
                            </span>
                        ))}
                    </div>
                    <div
                        style={{
                            display: "grid",
                            gridTemplateColumns: "repeat(3,1fr)",
                            gap: 12,
                            marginTop: 14,
                        }}
                    >
                        {[
                            ["Oak Lane 14", "Amsterdam-Zuid", "€ 420.000", "3 bd · 1 ba · 74 m²"],
                            [
                                "Harbor Loft 2B",
                                "Oostelijk Havengebied",
                                "€ 615.000",
                                "4 bd · 2 ba · 112 m²",
                            ],
                            ["Villa Sol", "Amstelveen", "€ 890.000", "6 bd · 3 ba · 204 m²"],
                        ].map(([t, a, p, m], i) => (
                            <div
                                key={t}
                                style={{
                                    border: "1px solid var(--border)",
                                    borderRadius: 10,
                                    overflow: "hidden",
                                    background: "var(--card)",
                                }}
                            >
                                <div
                                    style={{
                                        position: "relative",
                                        height: 54,
                                        background: `linear-gradient(135deg,var(--surface-inset),var(--surface-raised) ${55 + i * 15}%)`,
                                    }}
                                >
                                    <span
                                        style={{
                                            position: "absolute",
                                            left: 6,
                                            bottom: 6,
                                            background: "rgba(17,17,17,.85)",
                                            color: "#ffffff",
                                            fontSize: 9,
                                            fontWeight: 500,
                                            padding: "2px 7px",
                                            borderRadius: 5,
                                        }}
                                    >
                                        {p}
                                    </span>
                                    <LaunchIcon
                                        name="heart"
                                        size={11}
                                        style={{
                                            position: "absolute",
                                            right: 6,
                                            top: 6,
                                            color: "#ffffff",
                                            filter: "drop-shadow(0 1px 1px rgba(0,0,0,.3))",
                                        }}
                                    />
                                </div>
                                <div style={{ padding: "7px 9px" }}>
                                    <div
                                        style={{
                                            fontSize: 11,
                                            fontWeight: 600,
                                            color: "var(--foreground)",
                                        }}
                                    >
                                        {t}
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 9.5,
                                            color: "var(--faint-foreground)",
                                            marginTop: 1,
                                        }}
                                    >
                                        {a}
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 9.5,
                                            color: "var(--muted-foreground)",
                                            marginTop: 3,
                                        }}
                                    >
                                        {m}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                    <div
                        style={{
                            display: "flex",
                            alignItems: "center",
                            marginTop: 11,
                            fontSize: 10,
                            color: "var(--faint-foreground)",
                        }}
                    >
                        <span>128 results in Amsterdam</span>
                        <span style={{ marginLeft: "auto", color: "var(--muted-foreground)" }}>
                            Sort: Newest ▾
                        </span>
                    </div>
                </div>
                <svg
                    viewBox="0 0 24 24"
                    width="16"
                    height="16"
                    style={{
                        position: "absolute",
                        left: bs.cur.x,
                        top: bs.cur.y,
                        opacity: bs.cur.show ? 1 : 0,
                        transition:
                            "left .65s var(--ease-out), top .65s var(--ease-out), opacity .3s",
                        zIndex: 7,
                        filter: "drop-shadow(0 1px 2px rgba(0,0,0,.35))",
                    }}
                >
                    <path
                        d="M5.5 3.21V20.8c0 .45.54.67.85.35l4.86-4.86a.5.5 0 0 1 .35-.15h6.87c.45 0 .67-.54.35-.85L6.35 2.85a.5.5 0 0 0-.85.36z"
                        fill="#ffffff"
                        stroke="#000000"
                        strokeWidth="1"
                    />
                </svg>
            </div>
            <div
                style={{
                    flex: "none",
                    height: 24,
                    display: "flex",
                    alignItems: "center",
                    background: "var(--surface-raised)",
                    borderTop: "1px solid var(--border)",
                    fontFamily: "var(--font-mono)",
                    fontSize: 9.5,
                    color: "var(--muted-foreground)",
                    padding: "0 4px",
                }}
            >
                <span style={{ ...seg, color: "var(--accent)" }}>Toolbar</span>
                <span style={seg}>
                    <span style={{ color: "var(--foreground)" }}>GET</span> /
                </span>
                <span style={seg}>
                    <span
                        style={{
                            width: 5,
                            height: 5,
                            borderRadius: 99,
                            background: "var(--success)",
                        }}
                    />{" "}
                    200
                </span>
                <span style={seg}>58ms</span>
                <span style={seg}>12.4MB</span>
                <span style={seg}>4 queries</span>
                <span style={{ ...seg, borderRight: 0, marginLeft: "auto" }}>
                    Laravel 13 · local
                </span>
            </div>
        </div>
    );
}

export function AgentTerminal({ height = 400 }: { height?: number }) {
    const [tab, setTab] = React.useState(0);
    const [histS, setHistS] = React.useState<any[]>([]);
    const [phS, setPhS] = React.useState<any>({ mode: "idle" });
    const [histD, setHistD] = React.useState<any[]>([]);
    const [phD, setPhD] = React.useState<any>({ mode: "idle" });
    const [bs, setBS] = React.useState<any>({
        msgs: [
            { t: "sys", text: "Agentation connected. Click anything in the preview to annotate." },
        ],
        m1: false,
        m2: false,
        btn: false,
        h2: false,
        note: null,
        cur: { x: "68%", y: 180, show: false },
    });
    const [tick, setTick] = React.useState(0);
    const [copied, setCopied] = React.useState(false);
    const tabRef = React.useRef<any>(null);

    React.useEffect(() => {
        let iv: any;
        const start = () => {
            if (!iv && document.visibilityState !== "hidden") {
                iv = setInterval(() => setTick((t) => t + 1), 90);
            }
        };
        const stop = () => {
            if (iv) {
                clearInterval(iv);
                iv = null;
            }
        };
        const onVisibilityChange = () => {
            if (document.visibilityState === "hidden") {
                stop();
            } else {
                start();
            }
        };

        start();
        document.addEventListener("visibilitychange", onVisibilityChange);
        return () => {
            stop();
            document.removeEventListener("visibilitychange", onVisibilityChange);
        };
    }, []);

    const runScaffold = React.useCallback(() => {
        let ts: any[] = [];
        const at = (d: number, f: () => void) => ts.push(setTimeout(f, d));
        setHistS([]);
        setPhS({ mode: "idle" });
        let d = 300;
        at((d += 100), () => setPhS({ mode: "typing", text: AT_PROMPT, ch: 0 }));
        for (let i = 1; i <= AT_PROMPT.length; i++) {
            const cc = i;
            at((d += 18), () => setPhS((o: any) => ({ ...o, ch: cc })));
        }
        at((d += 300), () => {
            setHistS([{ t: "user", text: AT_PROMPT }]);
            setPhS({ mode: "spin", label: "Resolving packages..." });
        });
        at((d += 600), () => {
            setHistS((h) => [
                ...h,
                { t: "cmd", text: "composer create-project nckrtl/launch-starter-kit my-app" },
            ]);
            setPhS({
                mode: "select",
                q: "Which auth features to include?",
                options: [
                    "Full (2FA, passkeys, recovery codes, email verify)",
                    "Basic (login + register only)",
                    "None (bare skeleton)",
                ],
                sel: 0,
            });
        });
        at((d += 900), () => setPhS((o: any) => (o.mode === "select" ? { ...o, sel: 0 } : o)));
        at((d += 600), () => {
            setHistS((h) => [
                ...h,
                {
                    t: "pick",
                    q: "Auth features",
                    a: "Full (2FA, passkeys, recovery codes, email verify)",
                },
            ]);
            setPhS({
                mode: "select",
                q: "Install Filament admin panel?",
                options: ["Yes (at /admin)", "No"],
                sel: 0,
            });
        });
        at((d += 700), () => {
            setHistS((h) => [...h, { t: "pick", q: "Filament admin", a: "Yes (at /admin)" }]);
            setPhS({ mode: "spin", label: "Scaffolding Laravel 13 + React 19 + Inertia v3..." });
        });
        at((d += 1200), () => {
            setHistS((h) => [
                ...h,
                { t: "ok", text: "✔ Created 42 files (pages, components, auth, routes)" },
                { t: "ok", text: "✔ Generated type-safe routes with Wayfinder" },
                { t: "ok", text: "✔ Installed Base UI + shadcn base-nova components" },
                { t: "dim", text: "" },
                { t: "ready", text: "Done in 1.8s. Run 'composer dev' to start." },
            ]);
            setPhS({ mode: "idle" });
        });
        at((d += 2400), () => {
            if (tabRef.current) tabRef.current(1);
        });
        return () => ts.forEach(clearTimeout);
    }, []);

    const runBuild = React.useCallback(() => {
        let ts: any[] = [];
        const at = (d: number, f: () => void) => ts.push(setTimeout(f, d));
        setBS({
            msgs: [
                {
                    t: "sys",
                    text: "Agentation connected. Click anything in the preview to annotate.",
                },
            ],
            m1: false,
            m2: false,
            btn: false,
            h2: false,
            note: null,
            cur: { x: "72%", y: 140, show: false },
        });
        let d = 400;
        at((d += 400), () => setBS((o: any) => ({ ...o, cur: { x: "66%", y: 22, show: true } })));
        at((d += 750), () => setBS((o: any) => ({ ...o, m1: true })));
        at((d += 350), () =>
            setBS((o: any) => ({
                ...o,
                note: { t: 1, text: "Make this primary color" },
            })),
        );
        const N1 = "Make this primary color";
        for (let c = 1; c <= N1.length; c++) {
            const cc = c;
            at((d += 26), () =>
                setBS((o: any) =>
                    o.note ? { ...o, note: { ...o.note, text: N1.slice(0, cc) } } : o,
                ),
            );
        }
        at((d += 550), () =>
            setBS((o: any) => ({
                ...o,
                note: null,
                m1: false,
                msgs: [
                    ...o.msgs,
                    { t: "user", n: 1, text: "Make this primary color" },
                    { t: "sys", text: "Updating 'List your home' button component..." },
                ],
            })),
        );
        at((d += 650), () =>
            setBS((o: any) => ({
                ...o,
                btn: true,
                msgs: [...o.msgs, { t: "ok", text: "Button styled with var(--accent)" }],
            })),
        );
        at((d += 750), () => setBS((o: any) => ({ ...o, cur: { x: "12%", y: 110, show: true } })));
        at((d += 750), () => setBS((o: any) => ({ ...o, m2: true })));
        at((d += 350), () =>
            setBS((o: any) => ({
                ...o,
                note: { t: 2, text: "Make headline punchier" },
            })),
        );
        const N2 = "Make headline punchier";
        for (let c = 1; c <= N2.length; c++) {
            const cc = c;
            at((d += 26), () =>
                setBS((o: any) =>
                    o.note ? { ...o, note: { ...o.note, text: N2.slice(0, cc) } } : o,
                ),
            );
        }
        at((d += 550), () =>
            setBS((o: any) => ({
                ...o,
                note: null,
                m2: false,
                msgs: [
                    ...o.msgs,
                    { t: "user", n: 2, text: "Make headline punchier" },
                    { t: "sys", text: "Updating H1 in resources/js/pages/Home.tsx..." },
                ],
            })),
        );
        at((d += 650), () =>
            setBS((o: any) => ({
                ...o,
                h2: true,
                cur: { ...o.cur, show: false },
                msgs: [...o.msgs, { t: "ok", text: "Headline -> 'Find your next place, faster'" }],
            })),
        );
        at((d += 2800), () => {
            if (tabRef.current) tabRef.current(2);
        });
        return () => ts.forEach(clearTimeout);
    }, []);

    const runDeploy = React.useCallback(() => {
        let ts: any[] = [];
        const at = (d: number, f: () => void) => ts.push(setTimeout(f, d));
        setHistD([]);
        setPhD({ mode: "idle" });
        let d = 300;
        at((d += 100), () => setPhD({ mode: "typing", text: AT_DEPLOY, ch: 0 }));
        for (let i = 1; i <= AT_DEPLOY.length; i++) {
            const cc = i;
            at((d += 22), () => setPhD((o: any) => ({ ...o, ch: cc })));
        }
        at((d += 300), () => {
            setHistD([{ t: "user", text: AT_DEPLOY }]);
            setPhD({ mode: "spin", label: "Building production assets (VitePlus + Rolldown)..." });
        });
        at((d += 1100), () => {
            setHistD((h) => [
                ...h,
                { t: "ok", text: "✔ Bundled JS/CSS in 0.8s (gzip 116 kB)" },
                { t: "ok", text: "✔ Passed 15 Pest unit/feature tests" },
                { t: "ok", text: "✔ PHPStan level 9 check clean" },
            ]);
            setPhD({ mode: "spin", label: "Deploying to production target..." });
        });
        at((d += 1300), () => {
            setHistD((h) => [
                ...h,
                { t: "ok", text: "✔ Database migrations applied" },
                { t: "ok", text: "✔ SSL certificate provisioned" },
                { t: "dim", text: "" },
                { t: "ready", text: "🚀 Live at https://myapp.com (1.2s total)" },
            ]);
            setPhD({ mode: "idle" });
        });
        at((d += 3200), () => {
            if (tabRef.current) tabRef.current(0);
        });
        return () => ts.forEach(clearTimeout);
    }, []);

    React.useEffect(() => {
        let cancel: any = null;
        if (tab === 0) cancel = runScaffold();
        else if (tab === 1) cancel = runBuild();
        else if (tab === 2) cancel = runDeploy();
        return () => {
            if (cancel) cancel();
        };
    }, [tab, runScaffold, runBuild, runDeploy]);

    tabRef.current = (i: number) => setTab(i);

    const copy = () => {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(AT_PROMPT).catch(() => {});
        }
        setCopied(true);
        setTimeout(() => setCopied(false), 2200);
    };

    const cursor = (
        <span
            style={{
                display: "inline-block",
                width: 7,
                height: 13,
                marginLeft: 2,
                verticalAlign: "middle",
                background: "var(--foreground)",
                animation: "blink 1.1s steps(1) infinite",
            }}
        />
    );
    const inBuild = tab === 1;
    const bp = useBP();
    const mob = bp === 0;
    const split = inBuild && !mob;
    const term = tab === 2 ? { hist: histD, phase: phD } : { hist: histS, phase: phS };
    const { resolvedAppearance } = useAppearance();
    const isDark = resolvedAppearance === "dark";

    const stripePattern = `url("data:image/svg+xml,${encodeURIComponent(
        `<svg xmlns='http://www.w3.org/2000/svg' width='4' height='4'><path d='M-1,1 l2,-2 M0,4 l4,-4 M3,5 l2,-2' stroke='${
            isDark ? "rgba(255,255,255,0.06)" : "rgba(0,0,0,0.06)"
        }' stroke-width='1'/></svg>`,
    )}")`;

    return (
        <div
            id="agent-terminal"
            style={{
                position: "relative",
                textAlign: "left",
                maxWidth: mob ? "100%" : inBuild ? 900 : 640,
                margin: "0 auto",
                transition: "max-width .85s var(--ease-out)",
            }}
        >
            <ATCorner at="tl" />
            <ATCorner at="tr" />
            <ATCorner at="bl" />
            <ATCorner at="br" />
            <div
                style={{
                    height: mob ? 360 : height,
                    display: "flex",
                    flexDirection: "column",
                    backgroundColor: "var(--surface-card)",
                    backgroundImage: stripePattern,
                    border: "1px solid var(--border)",
                    borderRadius: 2,
                    overflow: "hidden",
                    fontFamily: "var(--font-mono)",
                    fontSize: mob ? 11.5 : 13,
                    lineHeight: 1.75,
                    boxShadow: "var(--shadow-overlay)",
                }}
            >
                <div
                    style={{
                        display: "flex",
                        alignItems: "center",
                        gap: 8,
                        padding: mob ? "7px 10px" : "7px 12px",
                        borderBottom: "1px solid var(--border)",
                        background: "var(--surface-raised)",
                        backdropFilter: "blur(6px)",
                        WebkitBackdropFilter: "blur(6px)",
                        flex: "none",
                    }}
                >
                    {!mob && (
                        <span style={{ display: "flex", gap: 5 }}>
                            {[0, 1, 2].map((i) => (
                                <span
                                    key={i}
                                    style={{
                                        width: 8,
                                        height: 8,
                                        borderRadius: 99,
                                        background: "var(--border-strong)",
                                    }}
                                />
                            ))}
                        </span>
                    )}
                    <ATTabs
                        tab={tab}
                        onTab={(i) => tabRef.current && tabRef.current(i)}
                        mob={mob}
                    />
                    <button
                        onClick={copy}
                        title="Copy prompt"
                        style={{
                            background: "none",
                            border: 0,
                            padding: 2,
                            cursor: "pointer",
                            color: copied ? "var(--success)" : "var(--faint-foreground)",
                            display: "flex",
                        }}
                    >
                        <LaunchIcon name={copied ? "check" : "copy"} size={13} />
                    </button>
                </div>
                <div
                    style={{
                        flex: 1,
                        minHeight: 0,
                        display: "grid",
                        gridTemplateColumns: split ? "300px 1fr" : "1fr 0fr",
                        transition: "grid-template-columns .85s var(--ease-out)",
                    }}
                >
                    <div
                        style={{
                            position: "relative",
                            minWidth: 0,
                            borderRight: "1px solid",
                            borderRightColor: split ? "var(--border)" : "transparent",
                            transition: "border-color .4s",
                        }}
                    >
                        <div
                            style={{
                                position: "absolute",
                                inset: 0,
                                display: "flex",
                                flexDirection: "column",
                                opacity: inBuild ? 0 : 1,
                                transition: "opacity .4s var(--ease-out)",
                                pointerEvents: inBuild ? "none" : "auto",
                            }}
                        >
                            <ATScrollback
                                hist={term.hist}
                                phase={term.phase}
                                tick={tick}
                                mob={mob}
                            />
                            <ATBottomBar phase={term.phase} cursor={cursor} mob={mob} />
                        </div>
                        <div
                            style={{
                                position: "absolute",
                                inset: 0,
                                opacity: inBuild ? 1 : 0,
                                transition: "opacity .45s .3s var(--ease-out)",
                                pointerEvents: inBuild ? "auto" : "none",
                            }}
                        >
                            <ATChat msgs={bs.msgs} cursor={cursor} />
                        </div>
                    </div>
                    <div style={{ position: "relative", minWidth: 0, overflow: "hidden" }}>
                        <div
                            style={{
                                position: "absolute",
                                top: 0,
                                bottom: 0,
                                left: 0,
                                width: 598,
                                transform: split ? "translateX(0)" : "translateX(-56px)",
                                opacity: split ? 1 : 0,
                                transition:
                                    "transform .85s var(--ease-out), opacity .55s .15s var(--ease-out)",
                            }}
                        >
                            <ATPreview bs={bs} />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
