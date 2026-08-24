import React from "react";
import { Frame, SectionDivider, SectionHeader, useBP } from "@/components/launch/grid";
import { LaunchIcon } from "@/components/launch/icons";
import { LayerChip } from "@/components/launch/layer-chip";
import { useAppearance } from "@/hooks/use-appearance";

export const LAYERS: [string | null, string, string, string[], string][] = [
    [
        "laravel",
        "Laravel",
        "The backbone. Routing, auth, queues, mail and the ORM that carries the whole app.",
        [
            "Routing",
            "Eloquent ORM",
            "Queues",
            "Async jobs",
            "Mail",
            "Cache",
            "Events",
            "Validation",
            "Countless packages",
        ],
        "#FF2D20",
    ],
    [
        "inertia",
        "Inertia",
        "The glue between Laravel and React. The SPA feel without building an API.",
        [
            "Prefetching",
            "Deferred props",
            "Infinite scroll",
            "Polling",
            "Partial reloads",
            "Form helpers",
            "SSR",
            "History encryption",
        ],
        "#1B5DFD",
    ],
    [
        "react",
        "React",
        "The UI runtime. Version 19 with actions and the compiler.",
        ["Components", "Hooks", "Suspense", "Actions", "Concurrent rendering", "React Compiler"],
        "#58C4DC",
    ],
    [
        "tailwindcss",
        "Tailwind CSS",
        "Takes care of styling. Version 4, zero config, design tokens included.",
        [
            "Utility classes",
            "Design tokens",
            "Dark mode",
            "Container queries",
            "JIT engine",
            "Zero config",
        ],
        "#00BAFF",
    ],
    [
        "vite",
        "Vite",
        "Instant dev server with HMR, built on the Rolldown and Oxc toolchain.",
        [
            "HMR",
            "Rolldown bundling",
            "Oxc parsing",
            "Oxfmt formatting",
            "TypeScript",
            "Code splitting",
        ],
        "#6253FC",
    ],
    [
        "baseui",
        "Base UI",
        "Unstyled, accessible primitives underneath every component.",
        [
            "Accessible primitives",
            "Focus management",
            "ARIA built in",
            "Keyboard navigation",
            "Portals",
        ],
        "#A1A1A1",
    ],
    [
        "shadcnui",
        "shadcn",
        "Does the heavy lifting behind every launch-ui component.",
        [
            "Off-the-shelf dashboards",
            "Easy to customize",
            "Dialogs",
            "Menus",
            "Forms",
            "Toasts",
            "Theming",
            "You own the code",
        ],
        "#10B981",
    ],
];

function ScrollList({ items, color, dur = 16 }: { items: string[]; color?: string; dur?: number }) {
    const mob = useBP() === 0;
    const row = (t: string, k: string | number) => (
        <div key={k} style={{ display: "flex", alignItems: "center", gap: 9, padding: "4px 0" }}>
            <LaunchIcon
                name="check"
                size={12}
                style={{ color: color || "var(--accent)", flex: "none" }}
            />
            <span className="mono-label" style={{ fontSize: 10.5 }}>
                {t}
            </span>
        </div>
    );

    return (
        <div
            style={{
                marginTop: mob ? 12 : 16,
                height: mob ? 150 : 210,
                overflow: "hidden",
                WebkitMaskImage:
                    "linear-gradient(180deg,transparent,#000 24%,#000 76%,transparent)",
                maskImage: "linear-gradient(180deg,transparent,#000 24%,#000 76%,transparent)",
            }}
        >
            <div style={{ animation: `libscroll ${dur}s linear infinite` }}>
                <div>{items.map((t, i) => row(t, i))}</div>
                <div>{items.map((t, i) => row(t, "b" + i))}</div>
            </div>
        </div>
    );
}

function StackSlider({
    act,
    onPick,
    onUser,
}: {
    act: number;
    onPick: (i: number) => void;
    onUser: () => void;
}) {
    const { resolvedAppearance } = useAppearance();
    const isDark = resolvedAppearance === "dark";
    const ref = React.useRef<HTMLDivElement>(null);
    const items = React.useRef<(HTMLDivElement | null)[]>([]);
    const lock = React.useRef(0);
    const W = 148;

    const center = (i: number, smooth: boolean) => {
        const el = ref.current;
        const it = items.current[i];
        if (!el || !it) return;
        const er = el.getBoundingClientRect();
        const ir = it.getBoundingClientRect();
        lock.current = Date.now() + (smooth ? 700 : 80);
        el.scrollTo({
            left: el.scrollLeft + (ir.left - er.left) - (er.width - ir.width) / 2,
            behavior: smooth ? "smooth" : "auto",
        });
    };

    React.useEffect(() => {
        center(act, true);
    }, [act]);

    const onScroll = () => {
        if (Date.now() < lock.current) return;
        const el = ref.current;
        if (!el) return;
        const er = el.getBoundingClientRect();
        const c = er.left + er.width / 2;
        let best = 0;
        let bd = 1e9;
        items.current.forEach((it, i) => {
            if (!it) return;
            const ir = it.getBoundingClientRect();
            const d = Math.abs(ir.left + ir.width / 2 - c);
            if (d < bd) {
                bd = d;
                best = i;
            }
        });
        onPick(best);
    };

    return (
        <div
            className="noscrollbar"
            ref={ref}
            onScroll={onScroll}
            onPointerDown={onUser}
            onTouchStart={onUser}
            style={{
                marginTop: 28,
                overflowX: "auto",
                overflowY: "hidden",
                scrollbarWidth: "none",
                msOverflowStyle: "none",
                scrollSnapType: "x mandatory",
                WebkitMaskImage: "linear-gradient(90deg,transparent,#000 20%,#000 80%,transparent)",
                maskImage: "linear-gradient(90deg,transparent,#000 20%,#000 80%,transparent)",
            }}
        >
            <div
                style={{
                    display: "flex",
                    gap: 8,
                    padding: `0 calc(50% - ${W / 2}px)`,
                    width: "max-content",
                }}
            >
                {LAYERS.map(([logo, name, , , color], i) => (
                    <div
                        key={name}
                        style={{
                            scrollSnapAlign: "center",
                            flex: "none",
                            width: W,
                        }}
                    >
                        <LayerChip
                            ref={(el) => {
                                items.current[i] = el;
                            }}
                            index={i}
                            name={name}
                            logo={logo}
                            color={color}
                            isActive={act === i}
                            isDark={isDark}
                            inactiveOpacity={0.65}
                            padding="0 13px"
                            onClick={() => {
                                onUser();
                                onPick(i);
                            }}
                        />
                    </div>
                ))}
            </div>
        </div>
    );
}

export function StackSection() {
    const { resolvedAppearance } = useAppearance();
    const isDark = resolvedAppearance === "dark";
    const mob = useBP() === 0;
    const [st, setSt] = React.useState({ act: 0, prev: -1 });
    const act = st.act;
    const up = st.act > st.prev;
    const go = (i: number) => setSt((o) => (o.act === i ? o : { act: i, prev: o.act }));
    const [auto, setAuto] = React.useState(true);
    const desktopTabRefs = React.useRef<(HTMLDivElement | null)[]>([]);

    const handleDesktopTabKeyDown = (e: React.KeyboardEvent, currentI: number) => {
        const visualOrder = LAYERS.map((_, idx) => idx).reverse();
        const pos = visualOrder.indexOf(currentI);
        let targetI: number | null = null;

        if (e.key === "ArrowDown" || e.key === "ArrowRight") {
            e.preventDefault();
            const nextPos = (pos + 1) % visualOrder.length;
            targetI = visualOrder[nextPos];
        } else if (e.key === "ArrowUp" || e.key === "ArrowLeft") {
            e.preventDefault();
            const prevPos = (pos - 1 + visualOrder.length) % visualOrder.length;
            targetI = visualOrder[prevPos];
        } else if (e.key === "Home") {
            e.preventDefault();
            targetI = visualOrder[0];
        } else if (e.key === "End") {
            e.preventDefault();
            targetI = visualOrder[visualOrder.length - 1];
        } else if (e.key === "Enter" || e.key === " ") {
            e.preventDefault();
            go(currentI);
            setAuto(false);
            return;
        }

        if (targetI !== null) {
            go(targetI);
            setAuto(false);
            desktopTabRefs.current[targetI]?.focus();
        }
    };

    React.useEffect(() => {
        if (!auto) return;
        let iv: any;
        const start = () => {
            if (!iv && document.visibilityState !== "hidden") {
                iv = setInterval(
                    () => setSt((o) => ({ act: (o.act + 1) % LAYERS.length, prev: o.act })),
                    3200,
                );
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
    }, [auto]);

    return (
        <div id="stack">
            <SectionDivider />
            <Frame style={{ padding: mob ? "56px 16px 20px" : "80px 32px 32px" }}>
                <div
                    style={{
                        position: "absolute",
                        inset: 0,
                        overflow: "hidden",
                        pointerEvents: "none",
                    }}
                >
                    {LAYERS.map(([logo], i) =>
                        logo ? (
                            <img
                                key={logo + i}
                                src={`/assets/logos/${logo}.svg`}
                                alt=""
                                style={{
                                    position: "absolute",
                                    left: "50%",
                                    top: "50%",
                                    transform: "translate(-50%,-50%)",
                                    height: mob ? 420 : 880,
                                    width: "auto",
                                    opacity: act === i ? (isDark ? 0.016 : 0.065) : 0,
                                    filter: isDark ? "brightness(0) invert(1)" : "none",
                                    transition: "opacity .5s var(--ease-out)",
                                }}
                            />
                        ) : null,
                    )}
                </div>
                <SectionHeader
                    eyebrow="The stack"
                    title="Proven tech, layer by layer."
                    sub="Every piece earns its place. Nothing exotic, nothing you'll fight."
                />
                <div
                    style={{
                        display: "grid",
                        gridTemplateColumns: mob ? "1fr" : "1fr 1fr",
                        gap: mob ? 28 : 64,
                        alignItems: "stretch",
                        marginTop: 24,
                    }}
                    onMouseLeave={() => setAuto(true)}
                >
                    {!mob && (
                        <div
                            role="tablist"
                            aria-orientation="vertical"
                            aria-label="Stack technologies"
                            style={{
                                display: "grid",
                                gap: 0,
                                alignContent: "center",
                                border: "1px solid var(--grid-line)",
                                borderRadius: 6,
                                overflow: "hidden",
                                background: "var(--surface-card)",
                                backdropFilter: "blur(12px)",
                                WebkitBackdropFilter: "blur(12px)",
                            }}
                        >
                            {LAYERS.map((l, i) => ({ l, i }))
                                .reverse()
                                .map(({ l: [logo, name, , , color], i }, idx) => {
                                    const isAct = act === i;
                                    return (
                                        <div
                                            key={name}
                                            ref={(el) => {
                                                desktopTabRefs.current[i] = el;
                                            }}
                                            role="tab"
                                            id={`stack-tab-${i}`}
                                            aria-controls={`stack-panel-${i}`}
                                            aria-selected={isAct}
                                            aria-label={name}
                                            tabIndex={isAct ? 0 : -1}
                                            onClick={() => {
                                                go(i);
                                                setAuto(false);
                                            }}
                                            onKeyDown={(e) => handleDesktopTabKeyDown(e, i)}
                                            onMouseEnter={() => {
                                                go(i);
                                                setAuto(false);
                                            }}
                                            className="focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--primary)]"
                                            style={{
                                                width: "100%",
                                                borderTop: idx
                                                    ? "1px solid var(--grid-line)"
                                                    : "none",
                                                padding: 4,
                                                boxSizing: "border-box",
                                                cursor: "pointer",
                                            }}
                                        >
                                            <LayerChip
                                                index={i}
                                                name={name}
                                                logo={logo}
                                                color={color}
                                                isActive={isAct}
                                                isDark={isDark}
                                                padding="0 16px"
                                                borderColor={isAct ? undefined : "transparent"}
                                                backgroundColor={isAct ? undefined : "transparent"}
                                                tabIndex={-1}
                                            />
                                        </div>
                                    );
                                })}
                        </div>
                    )}
                    <div style={{ position: "relative", minHeight: mob ? 286 : undefined }}>
                        {LAYERS.map(([, name, desc, feats, color], i) => {
                            const isAct = act === i;
                            return (
                                <div
                                    key={name}
                                    id={mob ? undefined : `stack-panel-${i}`}
                                    role={mob ? undefined : "tabpanel"}
                                    aria-labelledby={mob ? undefined : `stack-tab-${i}`}
                                    aria-hidden={!isAct}
                                    style={{
                                        position: "absolute",
                                        inset: 0,
                                        display: "flex",
                                        flexDirection: "column",
                                        justifyContent: mob ? "flex-start" : "center",
                                        alignItems: "flex-start",
                                        opacity: isAct ? 1 : 0,
                                        transform: isAct
                                            ? "translateY(0)"
                                            : st.prev === i
                                              ? up
                                                  ? "translateY(16px)"
                                                  : "translateY(-16px)"
                                              : up
                                                ? "translateY(-16px)"
                                                : "translateY(16px)",
                                        transition:
                                            "opacity .4s var(--ease-out), transform .4s var(--ease-out)",
                                        pointerEvents: isAct ? "auto" : "none",
                                    }}
                                >
                                    <div className="mono-label" style={{ color, marginBottom: 12 }}>
                                        LAYER 0{i + 1}
                                    </div>
                                    <h3
                                        style={{
                                            fontSize: mob ? 21 : 24,
                                            fontWeight: 500,
                                            letterSpacing: "-0.02em",
                                        }}
                                    >
                                        {name}
                                    </h3>
                                    <p
                                        style={{
                                            fontSize: mob ? 14.5 : 15,
                                            color: "var(--muted-foreground)",
                                            lineHeight: 1.65,
                                            marginTop: 10,
                                            textWrap: "pretty",
                                        }}
                                    >
                                        {desc}
                                    </p>
                                    {feats && <ScrollList items={feats} color={color} />}
                                </div>
                            );
                        })}
                    </div>
                    {mob && <StackSlider act={act} onPick={go} onUser={() => setAuto(false)} />}
                </div>
            </Frame>
        </div>
    );
}
