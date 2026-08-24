import React from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { IconTile } from "@/components/launch/brand";
import { CellMark, Frame, SectionDivider, SectionHeader, useBP } from "@/components/launch/grid";
import { LaunchIcon } from "@/components/launch/icons";
import { Marquee } from "@/components/launch/marquee";

const MOB_MARKS: Record<string, string> = { tl: "tbr", tr: "tbl" };

function Tile({
    icon,
    title,
    span,
    children,
    extra,
    fill,
    bl,
    marks,
}: {
    icon: string;
    title: string;
    span?: number;
    children: React.ReactNode;
    extra?: React.ReactNode;
    fill?: boolean;
    bl?: boolean;
    marks?: Record<string, string>;
}) {
    const [hov, setHov] = React.useState(false);
    const bp = useBP();
    const mob = bp === 0;
    const mk = mob ? MOB_MARKS : marks;

    return (
        <div
            onMouseEnter={() => setHov(true)}
            onMouseLeave={() => setHov(false)}
            style={{
                gridColumn: span && !mob ? `span ${span}` : undefined,
                position: "relative",
                zIndex: hov ? 3 : 1,
                minWidth: 0,
                borderLeft: bl && !mob ? "1px solid var(--grid-line)" : undefined,
                borderTop: "1px solid var(--grid-line)",
                background: hov ? "var(--card-hover-bg)" : "transparent",
                transition: "background .2s var(--ease-out)",
            }}
        >
            {mk &&
                Object.entries(mk).map(([at, arms]) => (
                    <CellMark key={at} at={at} arms={arms} on={hov} />
                ))}
            <div
                style={{
                    padding: mob ? "30px 16px" : "40px 36px",
                    display: "flex",
                    flexDirection: "column",
                    gap: mob ? 22 : 28,
                    overflow: "hidden",
                    height: "100%",
                    boxSizing: "border-box",
                }}
            >
                <IconTile name={icon} size="lg" active={hov} style={{ alignSelf: "flex-start" }} />
                <div style={{ display: "grid", gap: 6 }}>
                    <h3 style={{ fontSize: 16, fontWeight: 500 }}>{title}</h3>
                    <p style={{ fontSize: 14, color: "var(--muted-foreground)", lineHeight: 1.6 }}>
                        {children}
                    </p>
                </div>
                {fill ? (
                    <div
                        style={{
                            flex: 1,
                            minHeight: 150,
                            display: "flex",
                            flexDirection: "column",
                        }}
                    >
                        {extra}
                    </div>
                ) : (
                    extra
                )}
            </div>
        </div>
    );
}

const authSlides = () => [
    {
        k: "login",
        el: (
            <div style={{ display: "grid", gap: 10 }}>
                <div style={{ display: "grid", gap: 6 }}>
                    <label
                        htmlFor="auth-email-input"
                        style={{ fontSize: 12, fontWeight: 500, color: "var(--foreground)" }}
                    >
                        Email address
                    </label>
                    <Input id="auth-email-input" defaultValue="sam@myapp.test" readOnly />
                </div>
                <div style={{ display: "grid", gap: 6 }}>
                    <label
                        htmlFor="auth-password-input"
                        style={{ fontSize: 12, fontWeight: 500, color: "var(--foreground)" }}
                    >
                        Password
                    </label>
                    <Input
                        id="auth-password-input"
                        type="password"
                        defaultValue="password123"
                        readOnly
                    />
                </div>
                <div
                    style={{
                        display: "flex",
                        alignItems: "center",
                        justifyContent: "space-between",
                        marginTop: 2,
                    }}
                >
                    <label
                        htmlFor="auth-remember-checkbox"
                        style={{
                            display: "flex",
                            alignItems: "center",
                            gap: 8,
                            fontSize: 12,
                            color: "var(--muted-foreground)",
                            cursor: "pointer",
                        }}
                    >
                        <input
                            id="auth-remember-checkbox"
                            type="checkbox"
                            defaultChecked
                            readOnly
                            style={{ accentColor: "var(--accent)" }}
                        />
                        Remember me
                    </label>
                    <Button size="sm">Sign in</Button>
                </div>
            </div>
        ),
    },
    {
        k: "2fa",
        el: (
            <div style={{ display: "grid", gap: 10 }}>
                <div style={{ fontSize: 13, fontWeight: 500, color: "var(--foreground)" }}>
                    Two-factor code
                </div>
                <div style={{ display: "flex", gap: 6 }}>
                    {["4", "1", "9", "", "", ""].map((d, i) => (
                        <span
                            key={i}
                            style={{
                                width: 30,
                                height: 36,
                                border: "1px solid",
                                borderColor: d ? "var(--accent)" : "var(--border-strong)",
                                borderRadius: 4,
                                background: "transparent",
                                display: "flex",
                                alignItems: "center",
                                justifyContent: "center",
                                fontFamily: "var(--font-mono)",
                                fontSize: 14,
                                color: "var(--foreground)",
                            }}
                        >
                            {d}
                        </span>
                    ))}
                </div>
                <a
                    href="#features"
                    style={{ fontSize: 12.5, color: "var(--accent)", textDecoration: "none" }}
                >
                    Use a recovery code instead
                </a>
            </div>
        ),
    },
    {
        k: "passkey",
        el: (
            <div style={{ display: "grid", gap: 12, justifyItems: "center", textAlign: "center" }}>
                <IconTile name="scan-line" size="md" />
                <div style={{ fontSize: 13, fontWeight: 500, color: "var(--foreground)" }}>
                    Use your passkey
                </div>
                <div style={{ fontSize: 12, color: "var(--muted-foreground)" }}>
                    Touch ID, Face ID or security key
                </div>
                <Button size="sm" variant="outline">
                    Continue
                </Button>
            </div>
        ),
    },
    {
        k: "recovery",
        el: (
            <div style={{ display: "grid", gap: 10 }}>
                <div style={{ fontSize: 13, fontWeight: 500, color: "var(--foreground)" }}>
                    Recovery codes
                </div>
                <div
                    style={{
                        display: "grid",
                        gridTemplateColumns: "1fr 1fr",
                        gap: 6,
                        fontFamily: "var(--font-mono)",
                        fontSize: 11,
                        color: "var(--foreground)",
                    }}
                >
                    {["xk42-9df1", "mq87-3jd0", "pl56-1sa9", "vb23-8kf4"].map((c) => (
                        <span
                            key={c}
                            style={{
                                border: "1px solid var(--border)",
                                borderRadius: 3,
                                padding: "5px 9px",
                                background: "transparent",
                            }}
                        >
                            {c}
                        </span>
                    ))}
                </div>
                <div style={{ fontSize: 11.5, color: "var(--faint-foreground)" }}>
                    Stored once, hashed after download
                </div>
            </div>
        ),
    },
    {
        k: "verify",
        el: (
            <div style={{ display: "grid", gap: 12, justifyItems: "center", textAlign: "center" }}>
                <IconTile name="mail" size="md" />
                <div style={{ fontSize: 13, fontWeight: 500, color: "var(--foreground)" }}>
                    Verify your email
                </div>
                <div style={{ fontSize: 12, color: "var(--muted-foreground)" }}>
                    We sent a link to sam@myapp.test
                </div>
                <a
                    href="#features"
                    style={{ fontSize: 12.5, color: "var(--accent)", textDecoration: "none" }}
                >
                    Resend email
                </a>
            </div>
        ),
    },
];

function AuthShow() {
    const AUTH_SLIDES = React.useMemo(authSlides, []);
    const [i, setI] = React.useState(0);
    const mob = useBP() === 0;
    const inset = mob ? "-30px -16px" : "-40px -36px";

    React.useEffect(() => {
        let iv: any;
        const start = () => {
            if (!iv && document.visibilityState !== "hidden") {
                iv = setInterval(() => setI((v) => (v + 1) % AUTH_SLIDES.length), 5200);
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
    }, [AUTH_SLIDES.length]);

    return (
        <div style={{ position: "relative", minHeight: mob ? 268 : 250 }}>
            <div
                style={{
                    position: "absolute",
                    inset,
                    pointerEvents: "none",
                    background: "var(--auth-gradient)",
                }}
            />
            <div style={{ position: "absolute", inset: 0 }}>
                {AUTH_SLIDES.map((s, k) => (
                    <div
                        key={s.k}
                        aria-hidden={i !== k}
                        inert={i !== k ? true : undefined}
                        style={{
                            position: "absolute",
                            inset: 0,
                            display: "flex",
                            justifyContent: "flex-end",
                            opacity: i === k ? 1 : 0,
                            transform: i === k ? "translateY(0)" : "translateY(10px)",
                            transition:
                                "opacity .7s var(--ease-out), transform .7s var(--ease-out)",
                            pointerEvents: i === k ? "auto" : "none",
                        }}
                    >
                        <div
                            style={{
                                width: mob ? "100%" : "90%",
                                padding: mob ? "16px 16px 46px" : "20px 44px",
                                display: "flex",
                                flexDirection: "column",
                                justifyContent: "center",
                            }}
                        >
                            {s.el}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

const CODE_C: Record<string, string> = {
    k: "var(--accent)",
    s: "var(--success)",
    p: "var(--foreground)",
    d: "var(--faint-foreground)",
    v: "var(--muted-foreground)",
};

function Code({
    lines,
    file,
    bleed,
}: {
    lines: Array<Array<[string, string?]>>;
    file?: string;
    bleed?: boolean;
}) {
    const mob = useBP() === 0;
    const box = (
        <div
            style={{
                border: "1px solid var(--code-border)",
                borderRadius: bleed ? "5px 0 0 5px" : 5,
                background: "var(--code-bg)",
                overflow: "hidden",
                marginTop: bleed ? 0 : 2,
            }}
        >
            {file && (
                <div
                    style={{
                        padding: "6px 12px",
                        borderBottom: "1px solid var(--code-border)",
                        fontFamily: "var(--font-mono)",
                        fontSize: 10,
                        letterSpacing: ".04em",
                        color: "var(--faint-foreground)",
                        background: "var(--code-header-bg)",
                    }}
                >
                    {file}
                </div>
            )}
            <div
                style={{
                    padding: "10px 12px",
                    fontFamily: "var(--font-mono)",
                    fontSize: 11,
                    lineHeight: 1.85,
                    whiteSpace: "pre",
                    overflow: "hidden",
                }}
            >
                {lines.map((l, i) => (
                    <div key={i}>
                        {l.map(([t, c], j) => (
                            <span key={j} style={{ color: CODE_C[c || "p"] }}>
                                {t}
                            </span>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
    if (!bleed) return box;
    return (
        <div
            style={{
                margin: mob ? "2px -16px 0 0" : "2px -36px 0 0",
                WebkitMaskImage: "linear-gradient(90deg,var(--background) 70%,transparent 100%)",
                maskImage: "linear-gradient(90deg,var(--background) 70%,transparent 100%)",
            }}
        >
            {box}
        </div>
    );
}

const VITE_CODE: Array<Array<[string, string?]>> = [
    [
        ["import", "k"],
        [" { defineLaunchConfig } ", "p"],
        ["from", "k"],
        [" ", "p"],
        ["'launch-ui'", "s"],
    ],
    [[" ", "p"]],
    [
        ["export default", "k"],
        [" defineLaunchConfig()", "p"],
    ],
];

const WAY_CODE: Array<Array<[string, string?]>> = [
    [
        ["import", "k"],
        [" { show } ", "p"],
        ["from", "k"],
        [" ", "p"],
        ["'@/routes/posts'", "s"],
    ],
    [[" ", "p"]],
    [
        ["<Link", "k"],
        [" href", "v"],
        ["=", "d"],
        ["{show(post.id)}", "p"],
        [">", "d"],
    ],
    [["  {post.title}", "p"]],
    [["</Link>", "k"]],
];

const LANGS: [string, string, string][] = [
    [
        "English",
        "Welcome back, Sam",
        "Your projects are ready. Pick up where you left off, review what shipped overnight, and get the next release out the door before lunch.",
    ],
    [
        "Nederlands",
        "Welkom terug, Sam",
        "Je projecten staan klaar. Ga verder waar je gebleven was, bekijk wat er vannacht is uitgerold en verstuur de volgende release nog voor de lunch.",
    ],
    [
        "Deutsch",
        "Willkommen zurück, Sam",
        "Deine Projekte sind bereit. Mach weiter, wo du aufgehört hast, sieh dir an, was über Nacht live ging, und liefere das nächste Release noch vor dem Mittag.",
    ],
    [
        "Français",
        "Bon retour, Sam",
        "Vos projets sont prêts. Reprenez là où vous vous êtes arrêté, consultez ce qui a été livré cette nuit et publiez la prochaine version avant midi.",
    ],
];

function LangShow() {
    const [st, setSt] = React.useState({ i: 0, prev: -1, open: false, hover: -1 });

    React.useEffect(() => {
        let ts: any[] = [];
        const at = (d: number, f: () => void) => ts.push(setTimeout(f, d));
        const cycle = () => {
            ts = [];
            let d = 0;
            at((d += 1500), () => setSt((o) => ({ ...o, open: true, hover: o.i })));
            at((d += 650), () => setSt((o) => ({ ...o, hover: (o.i + 1) % LANGS.length })));
            at((d += 500), () =>
                setSt((o) => ({ i: (o.i + 1) % LANGS.length, prev: o.i, open: false, hover: -1 })),
            );
            at((d += 2400), cycle);
        };
        cycle();
        return () => ts.forEach(clearTimeout);
    }, []);

    const swap = (k: number): React.CSSProperties => ({
        position: "absolute",
        left: 0,
        top: 0,
        width: "100%",
        opacity: st.i === k ? 1 : 0,
        transform:
            st.i === k ? "translateY(0)" : st.prev === k ? "translateY(-12px)" : "translateY(12px)",
        transition: "opacity .4s var(--ease-out), transform .4s var(--ease-out)",
    });

    return (
        <div
            style={{
                display: "grid",
                gap: 14,
                marginTop: 4,
                flex: 1,
                gridTemplateRows: "auto 1fr",
                minHeight: 0,
            }}
        >
            <div style={{ position: "relative", width: "fit-content" }}>
                <div
                    style={{
                        display: "inline-flex",
                        alignItems: "center",
                        gap: 8,
                        border: "1px solid var(--border-strong)",
                        borderRadius: 4,
                        padding: "6px 11px",
                        background: "var(--surface-card)",
                    }}
                >
                    <LaunchIcon
                        name="globe"
                        size={13}
                        style={{ color: "var(--faint-foreground)" }}
                    />
                    <span
                        style={{
                            position: "relative",
                            width: 82,
                            height: 17,
                            display: "inline-block",
                            overflow: "hidden",
                        }}
                    >
                        {LANGS.map(([l], k) => (
                            <span
                                key={l}
                                style={{
                                    ...swap(k),
                                    fontSize: 12.5,
                                    lineHeight: "17px",
                                    color: "var(--foreground)",
                                    whiteSpace: "nowrap",
                                }}
                            >
                                {l}
                            </span>
                        ))}
                    </span>
                    <LaunchIcon
                        name="chevrons-up-down"
                        size={13}
                        style={{ color: "var(--faint-foreground)" }}
                    />
                </div>
                <div
                    style={{
                        position: "absolute",
                        top: "calc(100% + 6px)",
                        left: 0,
                        width: 150,
                        background: "var(--dropdown-bg)",
                        border: "1px solid var(--dropdown-border)",
                        borderRadius: 5,
                        padding: 4,
                        boxShadow: "var(--shadow-overlay)",
                        opacity: st.open ? 1 : 0,
                        transform: st.open
                            ? "translateY(0) scale(1)"
                            : "translateY(-4px) scale(.98)",
                        transformOrigin: "top left",
                        transition: "opacity .22s var(--ease-out), transform .22s var(--ease-out)",
                        pointerEvents: "none",
                        zIndex: 6,
                    }}
                >
                    {LANGS.map(([l], k) => (
                        <div
                            key={l}
                            style={{
                                display: "flex",
                                alignItems: "center",
                                justifyContent: "space-between",
                                padding: "5px 9px",
                                borderRadius: 6,
                                fontSize: 12,
                                color:
                                    st.hover === k
                                        ? "var(--foreground)"
                                        : "var(--muted-foreground)",
                                background: st.hover === k ? "var(--dropdown-hover)" : "none",
                                transition: "background .15s, color .15s",
                            }}
                        >
                            {l}
                            {st.i === k && (
                                <LaunchIcon
                                    name="check"
                                    size={12}
                                    style={{ color: "var(--accent)" }}
                                />
                            )}
                        </div>
                    ))}
                </div>
            </div>
            <div
                style={{
                    position: "relative",
                    minHeight: 110,
                    overflow: "hidden",
                    WebkitMaskImage: "linear-gradient(180deg,#000 45%,transparent 100%)",
                    maskImage: "linear-gradient(180deg,#000 45%,transparent 100%)",
                }}
            >
                {LANGS.map(([l, g, p], k) => (
                    <div key={l} style={swap(k)}>
                        <div
                            style={{
                                fontSize: 15.5,
                                fontWeight: 500,
                                letterSpacing: "-0.01em",
                                color: "var(--foreground)",
                                whiteSpace: "nowrap",
                            }}
                        >
                            {g}
                        </div>
                        <div
                            style={{
                                fontSize: 12.5,
                                lineHeight: 1.6,
                                color: "var(--muted-foreground)",
                                marginTop: 6,
                            }}
                        >
                            {p}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

function AnnoDemo() {
    const mob = useBP() === 0;
    const [s, setS] = React.useState({
        cur: { x: "80%", y: 12 },
        m1: false,
        m2: false,
        n: null as any,
        btn: false,
        chart: false,
    });

    React.useEffect(() => {
        let ts: any[] = [];
        const at = (d: number, f: () => void) => ts.push(setTimeout(f, d));
        const N1 = "Make it orange";
        const N2 = "Add a chart";
        const run = () => {
            ts = [];
            setS({
                cur: { x: "80%", y: 12 },
                m1: false,
                m2: false,
                n: null,
                btn: false,
                chart: false,
            });
            let d = 300;
            at((d += 350), () => setS((o) => ({ ...o, cur: { x: "44px", y: "92px" } })));
            at((d += 700), () => setS((o) => ({ ...o, m1: true })));
            at((d += 280), () => setS((o) => ({ ...o, n: { t: 1, text: "" } })));
            for (let c = 1; c <= N1.length; c++) {
                const cc = c;
                at((d += 28), () =>
                    setS((o: any) => (o.n ? { ...o, n: { ...o.n, text: N1.slice(0, cc) } } : o)),
                );
            }
            at((d += 480), () => setS((o) => ({ ...o, n: null, m1: false, btn: true })));
            at((d += 650), () => setS((o) => ({ ...o, cur: { x: "68%", y: "58px" } })));
            at((d += 700), () => setS((o) => ({ ...o, m2: true })));
            at((d += 280), () => setS((o) => ({ ...o, n: { t: 2, text: "" } })));
            for (let c = 1; c <= N2.length; c++) {
                const cc = c;
                at((d += 28), () =>
                    setS((o: any) => (o.n ? { ...o, n: { ...o.n, text: N2.slice(0, cc) } } : o)),
                );
            }
            at((d += 480), () => setS((o) => ({ ...o, n: null, m2: false, chart: true })));
            at((d += 2600), run);
        };
        run();
        return () => ts.forEach(clearTimeout);
    }, []);

    const mark = (on: boolean, r: number): React.CSSProperties => ({
        position: "absolute",
        inset: -5,
        border: "1px dashed var(--accent)",
        borderRadius: r,
        opacity: on ? 1 : 0,
        transform: on ? "scale(1)" : "scale(.9)",
        transition: "opacity .25s, transform .25s var(--ease-out)",
        pointerEvents: "none",
    });

    const badge = (n: number) => (
        <span
            style={{
                position: "absolute",
                top: -7,
                right: -7,
                width: 13,
                height: 13,
                borderRadius: 99,
                background: "var(--accent)",
                color: "#fff",
                fontSize: 8.5,
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                fontFamily: "var(--font-mono)",
            }}
        >
            {n}
        </span>
    );

    const note = (w: number): React.CSSProperties => ({
        position: "absolute",
        width: w,
        background: "var(--dropdown-bg)",
        border: "1px solid var(--dropdown-border)",
        borderRadius: 8,
        padding: "6px 9px",
        fontFamily: "var(--font-mono)",
        fontSize: 10.5,
        lineHeight: 1.5,
        color: "var(--foreground)",
        boxShadow: "var(--shadow-overlay)",
        whiteSpace: "normal",
        zIndex: 4,
    });

    const caret = (
        <span
            style={{
                display: "inline-block",
                width: 5,
                height: 10,
                marginLeft: 2,
                verticalAlign: "-1px",
                background: "var(--muted-foreground)",
                animation: "blink 1.1s steps(1) infinite",
            }}
        />
    );

    return (
        <div
            style={{
                position: "relative",
                background: "transparent",
                border: "1px solid var(--border)",
                borderRadius: 5,
                padding: 12,
                fontFamily: "var(--font-sans)",
                marginTop: 4,
                minHeight: 158,
                flex: 1,
            }}
        >
            <div
                style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 7,
                    paddingBottom: 9,
                    borderBottom: "1px solid var(--border)",
                }}
            >
                <span
                    style={{ width: 8, height: 8, borderRadius: 2, background: "var(--accent)" }}
                />
                <span
                    style={{
                        height: 5,
                        width: 22,
                        borderRadius: 3,
                        background: "var(--border-strong)",
                    }}
                />
                <span
                    style={{
                        height: 5,
                        width: 16,
                        borderRadius: 3,
                        background: "var(--border-strong)",
                    }}
                />
                <span
                    style={{
                        marginLeft: "auto",
                        width: 13,
                        height: 13,
                        borderRadius: 99,
                        background: "var(--border-strong)",
                    }}
                />
            </div>
            <div style={{ display: "flex", gap: 12, marginTop: 12 }}>
                <div style={{ flex: 1.15 }}>
                    <div
                        style={{
                            height: 8,
                            width: "92%",
                            borderRadius: 4,
                            background: "var(--border-strong)",
                        }}
                    />
                    <div
                        style={{
                            height: 8,
                            width: "68%",
                            borderRadius: 4,
                            background: "var(--border)",
                            marginTop: 6,
                        }}
                    />
                    <span style={{ position: "relative", display: "inline-block", marginTop: 14 }}>
                        <span
                            style={{
                                display: "inline-block",
                                fontSize: 11,
                                padding: "5px 11px",
                                borderRadius: 3,
                                border: "1px solid",
                                transition: "all .35s var(--ease-out)",
                                background: s.btn ? "var(--accent)" : "var(--surface-raised)",
                                borderColor: s.btn ? "transparent" : "var(--border-strong)",
                                color: s.btn ? "#fff" : "var(--foreground)",
                                boxShadow: s.btn ? "var(--glow-accent)" : "none",
                            }}
                        >
                            Get started
                        </span>
                        <span style={mark(s.m1, 9)}>{badge(1)}</span>
                        {s.n && s.n.t === 1 && (
                            <span
                                style={{
                                    ...note(126),
                                    ...(mob
                                        ? { top: "calc(100% + 9px)", left: 0 }
                                        : { top: -4, left: "calc(100% + 12px)" }),
                                }}
                            >
                                {s.n.text}
                                {caret}
                            </span>
                        )}
                    </span>
                </div>
                <div style={{ flex: 1, position: "relative" }}>
                    <div
                        style={{
                            border: "1px solid var(--border)",
                            borderRadius: 4,
                            background: "transparent",
                            padding: 9,
                            minHeight: 66,
                        }}
                    >
                        {s.chart ? (
                            <div
                                style={{
                                    display: "flex",
                                    alignItems: "flex-end",
                                    gap: 5,
                                    height: 48,
                                }}
                            >
                                {[22, 34, 27, 42, 31].map((h, i) => (
                                    <span
                                        key={i}
                                        style={{
                                            width: 9,
                                            height: h,
                                            borderRadius: 2,
                                            background: "var(--accent)",
                                            opacity: 0.55 + i * 0.09,
                                            transformOrigin: "bottom",
                                            animation: `chartup .5s var(--ease-out) ${i * 70}ms backwards`,
                                        }}
                                    />
                                ))}
                            </div>
                        ) : (
                            <div>
                                <div
                                    style={{
                                        height: 5,
                                        width: "70%",
                                        borderRadius: 3,
                                        background: "var(--border-strong)",
                                    }}
                                />
                                <div
                                    style={{
                                        height: 5,
                                        width: "50%",
                                        borderRadius: 3,
                                        background: "var(--border)",
                                        marginTop: 6,
                                    }}
                                />
                                <div
                                    style={{
                                        height: 5,
                                        width: "60%",
                                        borderRadius: 3,
                                        background: "var(--border)",
                                        marginTop: 6,
                                    }}
                                />
                            </div>
                        )}
                    </div>
                    <span style={mark(s.m2, 10)}>{badge(2)}</span>
                    {s.n && s.n.t === 2 && (
                        <span style={{ ...note(110), top: "calc(100% + 10px)", right: 0 }}>
                            {s.n.text}
                            {caret}
                        </span>
                    )}
                </div>
            </div>
            <svg
                viewBox="0 0 24 24"
                width="14"
                height="14"
                style={{
                    position: "absolute",
                    left: s.cur.x,
                    top: s.cur.y,
                    transition: "left .6s var(--ease-out), top .6s var(--ease-out)",
                    zIndex: 5,
                    filter: "drop-shadow(0 1px 2px rgba(0,0,0,.35))",
                }}
            >
                <path
                    d="M5.5 3.21V20.8c0 .45.54.67.85.35l4.86-4.86a.5.5 0 0 1 .35-.15h6.87c.45 0 .67-.54.35-.85L6.35 2.85a.5.5 0 0 0-.85.36z"
                    fill="#fff"
                    stroke="#000"
                    strokeWidth="1"
                />
            </svg>
        </div>
    );
}

function FilamentShot() {
    const mob = useBP() === 0;
    const amber = "var(--accent)";

    return (
        <div
            style={{
                border: "1px solid var(--border)",
                borderRadius: 5,
                overflow: "hidden",
                display: "flex",
                background: "var(--surface-card)",
                margin: mob ? "4px 0 -30px" : "4px 0 -40px",
                minHeight: 150,
                WebkitMaskImage: "linear-gradient(180deg,#000 60%,transparent 100%)",
                maskImage: "linear-gradient(180deg,#000 60%,transparent 100%)",
            }}
        >
            <div
                style={{
                    width: 54,
                    flex: "none",
                    borderRight: "1px solid var(--border)",
                    padding: 9,
                    display: "grid",
                    gap: 7,
                    alignContent: "start",
                }}
            >
                <span style={{ width: 10, height: 10, borderRadius: 3, background: amber }} />
                {[34, 26, 30, 22].map((w, i) => (
                    <span
                        key={i}
                        style={{
                            height: 5,
                            width: w,
                            borderRadius: 3,
                            background: i === 0 ? "var(--accent-glow)" : "var(--border)",
                        }}
                    />
                ))}
            </div>
            <div style={{ flex: 1, padding: 10 }}>
                <div style={{ display: "flex", gap: 8 }}>
                    {[
                        ["Users", "128"],
                        ["Revenue", "€ 4.2k"],
                    ].map(([l, v]) => (
                        <div
                            key={l}
                            style={{
                                flex: 1,
                                border: "1px solid var(--border)",
                                borderRadius: 4,
                                padding: "6px 9px",
                                background: "var(--surface-raised)",
                            }}
                        >
                            <div
                                style={{
                                    fontSize: 8.5,
                                    color: "var(--faint-foreground)",
                                    fontFamily: "var(--font-mono)",
                                    letterSpacing: ".05em",
                                    textTransform: "uppercase",
                                }}
                            >
                                {l}
                            </div>
                            <div style={{ fontSize: 12, color: "var(--foreground)", marginTop: 2 }}>
                                {v}
                            </div>
                        </div>
                    ))}
                </div>
                <div
                    style={{
                        marginTop: 9,
                        border: "1px solid var(--border)",
                        borderRadius: 4,
                        overflow: "hidden",
                    }}
                >
                    {[0, 1, 2].map((i) => (
                        <div
                            key={i}
                            style={{
                                display: "flex",
                                alignItems: "center",
                                gap: 7,
                                padding: "5px 8px",
                                borderTop: i ? "1px solid var(--border)" : "none",
                            }}
                        >
                            <span
                                style={{
                                    width: 8,
                                    height: 8,
                                    borderRadius: 99,
                                    background: "var(--border-strong)",
                                }}
                            />
                            <span
                                style={{
                                    height: 5,
                                    width: 60 - i * 12,
                                    borderRadius: 3,
                                    background: "var(--border)",
                                }}
                            />
                            <span
                                style={{
                                    marginLeft: "auto",
                                    height: 5,
                                    width: 22,
                                    borderRadius: 3,
                                    background: i === 0 ? "var(--accent-glow)" : "var(--border)",
                                }}
                            />
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function MailShow() {
    const mob = useBP() === 0;
    const mail = (title: string, btn: string) => (
        <div
            key={title}
            style={{
                width: 236,
                flex: "none",
                background: "var(--card)",
                border: "1px solid var(--border)",
                borderRadius: 5,
                padding: 18,
                fontFamily: "var(--font-sans)",
            }}
        >
            <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
                <span
                    style={{ width: 9, height: 9, borderRadius: 2, background: "var(--accent)" }}
                />
                <span style={{ fontSize: 10.5, fontWeight: 600, color: "var(--foreground)" }}>
                    myapp
                </span>
            </div>
            <div
                style={{ fontSize: 13, fontWeight: 500, color: "var(--foreground)", marginTop: 12 }}
            >
                {title}
            </div>
            <div
                style={{
                    height: 6,
                    width: "85%",
                    borderRadius: 3,
                    background: "var(--border-strong)",
                    marginTop: 9,
                }}
            />
            <div
                style={{
                    height: 6,
                    width: "60%",
                    borderRadius: 3,
                    background: "var(--border)",
                    marginTop: 5,
                }}
            />
            <div
                style={{
                    display: "inline-block",
                    marginTop: 12,
                    padding: "3px 9px",
                    borderRadius: 2,
                    background: "var(--accent)",
                    color: "#fff",
                    fontSize: 9.5,
                    fontWeight: 500,
                }}
            >
                {btn}
            </div>
            <div
                style={{
                    height: 5,
                    width: "45%",
                    borderRadius: 3,
                    background: "var(--border)",
                    marginTop: 14,
                }}
            />
        </div>
    );

    return (
        <div
            style={{
                margin: mob ? "0 0 -30px" : "0 0 -40px",
                WebkitMaskImage: "linear-gradient(180deg,#000 55%,transparent 100%)",
                maskImage: "linear-gradient(180deg,#000 55%,transparent 100%)",
            }}
        >
            <div
                style={{
                    display: "flex",
                    gap: 14,
                    marginTop: 4,
                    overflow: "hidden",
                    WebkitMaskImage: "linear-gradient(90deg,#000 55%,transparent 98%)",
                    maskImage: "linear-gradient(90deg,#000 55%,transparent 98%)",
                }}
            >
                {mail("Verify your email address", "Confirm email")}
                {mail("Reset your password", "Reset password")}
                {mail("Your receipt", "View invoice")}
            </div>
        </div>
    );
}

const AUTH_FEATS = [
    "Login",
    "Register",
    "Forgot password",
    "Reset password",
    "Verify email",
    "Two-factor codes",
    "Passkeys",
    "Recovery codes",
    "OTP confirmation",
    "Account deletion",
];

function AuthTicker() {
    const chip = (t: string, k: string | number) => (
        <span
            key={k}
            style={{
                display: "inline-flex",
                alignItems: "center",
                gap: 7,
                flex: "none",
                padding: "9px 16px",
                background: "var(--marquee-chip-bg)",
                borderRight: "1px solid var(--grid-line)",
            }}
        >
            <LaunchIcon name="check" size={12} style={{ color: "var(--accent)", flex: "none" }} />
            <span className="mono-label" style={{ fontSize: 10 }}>
                {t}
            </span>
        </span>
    );

    return (
        <Marquee
            duration="50s"
            fadeWidth={90}
            maskGradient="linear-gradient(90deg, transparent, #000 12%, #000 88%, transparent)"
            style={{
                position: "absolute",
                left: 0,
                right: 0,
                bottom: 0,
                borderTop: "1px solid var(--grid-line)",
            }}
        >
            {AUTH_FEATS.map((t, i) => chip(t, i))}
            {AUTH_FEATS.map((t, i) => chip(t, "b" + i))}
        </Marquee>
    );
}

export function Features() {
    const [authHov, setAuthHov] = React.useState(false);
    const mob = useBP() === 0;

    return (
        <div id="features">
            <SectionDivider />
            <Frame
                crosses={["tl", "tr", "bl-accent", "br"]}
                style={{ padding: mob ? "56px 16px" : "80px 32px" }}
            >
                <SectionHeader
                    eyebrow="What's inside"
                    title="Everything you need to start."
                    sub="A starter kit and a UI library that were built for each other."
                />
                <div
                    style={{
                        margin: mob ? "0 -16px -56px" : "0 -32px -80px",
                        display: "grid",
                        gridTemplateColumns: mob ? "1fr" : "repeat(3,1fr)",
                    }}
                >
                    <div
                        onMouseEnter={() => setAuthHov(true)}
                        onMouseLeave={() => setAuthHov(false)}
                        style={{
                            gridColumn: mob ? undefined : "span 2",
                            position: "relative",
                            zIndex: authHov ? 3 : 1,
                            minWidth: 0,
                            borderTop: "1px solid var(--grid-line)",
                            background: authHov ? "var(--card-hover-bg)" : "transparent",
                            transition: "background .2s var(--ease-out)",
                        }}
                    >
                        <CellMark at="tl" arms="tbr" on={authHov} />
                        <CellMark at="tr" arms={mob ? "tbl" : "lrb"} on={authHov} />
                        {!mob && <CellMark at="bl" arms="tbr" on={authHov} />}
                        {!mob && <CellMark at="br" arms="tblr" on={authHov} />}
                        <div
                            style={{
                                position: "relative",
                                minWidth: 0,
                                padding: mob ? "30px 16px" : "40px 36px",
                                display: "grid",
                                gridTemplateColumns: mob ? "1fr" : "1fr 1fr",
                                gap: mob ? 24 : 28,
                                overflow: "hidden",
                                height: "100%",
                                boxSizing: "border-box",
                            }}
                        >
                            <div
                                style={{
                                    display: "grid",
                                    gap: mob ? 22 : 28,
                                    minWidth: 0,
                                    alignContent: "start",
                                }}
                            >
                                <IconTile name="shield-check" size="lg" active={authHov} />
                                <div style={{ display: "grid", gap: 8 }}>
                                    <h3
                                        style={{
                                            fontSize: 17,
                                            fontWeight: 500,
                                            letterSpacing: "-0.01em",
                                        }}
                                    >
                                        Auth, done
                                    </h3>
                                    <p
                                        style={{
                                            fontSize: 14,
                                            lineHeight: 1.65,
                                            color: "var(--muted-foreground)",
                                            textWrap: "pretty",
                                        }}
                                    >
                                        Every flow you never want to build again, shipped and
                                        tested. Login and registration, two-factor, passkeys,
                                        recovery codes, email verification, all the way to account
                                        deletion.
                                    </p>
                                </div>
                            </div>
                            <AuthShow />
                            <AuthTicker />
                        </div>
                    </div>
                    <Tile
                        icon="zap"
                        title="Zero-config Vite"
                        bl
                        marks={{ tl: "lrb", tr: "tbl", bl: "tblr", br: "tbl" }}
                        extra={<Code lines={VITE_CODE} file="vite.config.ts" bleed />}
                    >
                        One line in vite.config.ts wires Laravel, React and Inertia.
                    </Tile>
                    <Tile
                        icon="git-branch"
                        title="Type-safe routes"
                        marks={{ tl: "tbr", tr: "lrb", bl: "tbr", br: "tblr" }}
                        extra={<Code lines={WAY_CODE} file="resources/js/pages/Posts.tsx" />}
                    >
                        Laravel Wayfinder generates typed route helpers from your controllers.
                    </Tile>
                    <Tile
                        icon="languages"
                        title="i18n built in"
                        bl
                        fill
                        marks={{ tl: "lrb", tr: "tblr", bl: "tblr", br: "lrt" }}
                        extra={<LangShow />}
                    >
                        Translation helpers ready before your second market is.
                    </Tile>
                    <Tile
                        icon="sparkles"
                        title="Agentation"
                        bl
                        fill
                        marks={{ tl: "tblr", tr: "tbl", bl: "lrt", br: "tbl" }}
                        extra={<AnnoDemo />}
                    >
                        A feedback toolbar for agent-driven development, integrated out of the box.
                    </Tile>
                    <Tile
                        icon="filament"
                        title="Filament ready"
                        marks={{ tl: "tbr", tr: "tblr", bl: "tblr", br: "lrt" }}
                        extra={<FilamentShot />}
                    >
                        Say yes during scaffold and get a wired admin panel at /admin.
                    </Tile>
                    <Tile
                        icon="mail"
                        title="Email templates"
                        span={2}
                        bl
                        marks={{ tl: "tblr", tr: "tbl", bl: "lrt", br: "tblr" }}
                        extra={<MailShow />}
                    >
                        Transactional mail that matches the brand: verification, resets, receipts.
                    </Tile>
                </div>
            </Frame>
        </div>
    );
}
