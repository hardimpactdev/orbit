import React from "react";
import { cn } from "@/lib/utils";

export const COL: React.CSSProperties = { maxWidth: 1400, margin: "0 auto", position: "relative" };
export const MOBW: React.CSSProperties = { width: "calc(100% - 32px)" };

function subscribeBP(callback: () => void) {
    if (typeof window === "undefined") return () => {};
    window.addEventListener("resize", callback);
    return () => window.removeEventListener("resize", callback);
}

function getSnapshotBP(): 0 | 1 | 2 {
    if (typeof window === "undefined") return 2;
    const w = window.innerWidth;
    return w < 760 ? 0 : w < 1080 ? 1 : 2;
}

function getServerSnapshotBP(): 0 | 1 | 2 {
    return 2;
}

export function useBP(): 0 | 1 | 2 {
    return React.useSyncExternalStore(subscribeBP, getSnapshotBP, getServerSnapshotBP);
}

export function Cross({ pos, on }: { pos: React.CSSProperties; on?: boolean }) {
    const c = on ? "var(--accent)" : "var(--grid-cross)";
    const bar: React.CSSProperties = {
        position: "absolute",
        background: c,
        transition: "background var(--dur-fast) var(--ease-out)",
    };
    return (
        <span
            style={{
                position: "absolute",
                width: 9,
                height: 9,
                zIndex: on ? 3 : 2,
                pointerEvents: "none",
                ...pos,
            }}
        >
            <span style={{ ...bar, left: 0, right: 0, top: 4, height: 1 }} />
            <span style={{ ...bar, top: 0, bottom: 0, left: 4, width: 1 }} />
        </span>
    );
}

export const CROSS_POS: Record<string, React.CSSProperties> = {
    tl: { top: -5, left: -5 },
    tr: { top: -5, right: -5 },
    bl: { bottom: -5, left: -5 },
    br: { bottom: -5, right: -5 },
};

const headerListeners = new Set<(highlight: boolean) => void>();
let isHeaderHighlighted = false;
let currentHoveredEl: HTMLElement | null = null;

function setHeaderHighlight(highlight: boolean) {
    if (isHeaderHighlighted !== highlight) {
        isHeaderHighlighted = highlight;
        headerListeners.forEach((listener) => listener(highlight));
    }
}

function checkCurrentOverlap() {
    if (!currentHoveredEl) {
        setHeaderHighlight(false);
        return;
    }

    const rect = currentHoveredEl.getBoundingClientRect();
    const headerEl = document.querySelector("header");
    const headerBottom = headerEl ? headerEl.getBoundingClientRect().bottom : 64;

    const overlaps = rect.top <= headerBottom + 2 && rect.bottom >= 0;
    setHeaderHighlight(overlaps);
}

if (typeof window !== "undefined") {
    window.addEventListener("scroll", checkCurrentOverlap, { passive: true });
}

export function useHeaderHighlight(): boolean {
    const [highlight, setHighlight] = React.useState(isHeaderHighlighted);

    React.useEffect(() => {
        headerListeners.add(setHighlight);
        return () => {
            headerListeners.delete(setHighlight);
        };
    }, []);

    return highlight;
}

export function Tee({ pos, on }: { pos: React.CSSProperties; on?: boolean }) {
    const c = on ? "var(--accent)" : "var(--grid-cross)";
    const bar: React.CSSProperties = {
        position: "absolute",
        background: c,
        transition: "background var(--dur-fast) var(--ease-out)",
    };
    return (
        <span
            style={{
                position: "absolute",
                width: 9,
                height: 9,
                zIndex: 3,
                pointerEvents: "none",
                ...pos,
            }}
        >
            <span style={{ ...bar, left: 0, right: 0, top: 4, height: 1 }} />
            <span style={{ ...bar, top: 4, bottom: 0, left: 4, width: 1 }} />
        </span>
    );
}

export function CellMark({ at, arms, on }: { at: string; arms: string; on?: boolean }) {
    const A: Record<string, React.CSSProperties> = {
        t: { left: 4, top: 0, width: 1, height: 5 },
        b: { left: 4, top: 4, width: 1, height: 5 },
        l: { top: 4, left: 0, height: 1, width: 5 },
        r: { top: 4, left: 4, height: 1, width: 5 },
    };
    const pos: React.CSSProperties = {
        position: "absolute",
        width: 9,
        height: 9,
        zIndex: 2,
        pointerEvents: "none",
    };
    pos[at[0] === "t" ? "top" : "bottom"] = -5;
    pos[at[1] === "l" ? "left" : "right"] = -5;

    return (
        <span style={pos}>
            {arms.split("").map((a) => (
                <span
                    key={a}
                    style={{ position: "absolute", background: "var(--grid-cross)", ...A[a] }}
                />
            ))}
            {arms.split("").map((a) => (
                <span
                    key={"a" + a}
                    style={{
                        position: "absolute",
                        background: "var(--accent)",
                        opacity: on ? 1 : 0,
                        transition: "opacity var(--dur-fast) var(--ease-out)",
                        ...A[a],
                    }}
                />
            ))}
        </span>
    );
}

interface FrameProps extends React.HTMLAttributes<HTMLDivElement> {
    crosses?: boolean | string[];
}

export function Frame({ children, crosses = true, style, className, ...props }: FrameProps) {
    const [hover, setHover] = React.useState(false);
    const bp = useBP();
    const list = crosses === true ? ["tl", "tr", "bl", "br"] : crosses || [];

    const handleMouseEnter = (e: React.MouseEvent<HTMLDivElement>) => {
        setHover(true);
        currentHoveredEl = e.currentTarget;
        checkCurrentOverlap();
        props.onMouseEnter?.(e);
    };

    const handleMouseLeave = (e: React.MouseEvent<HTMLDivElement>) => {
        setHover(false);
        if (currentHoveredEl === e.currentTarget) {
            currentHoveredEl = null;
            checkCurrentOverlap();
        }
        props.onMouseLeave?.(e);
    };

    return (
        <div
            className={cn("grid-frame", className)}
            style={{ ...COL, ...(bp === 0 ? MOBW : null), ...style }}
            onMouseEnter={handleMouseEnter}
            onMouseLeave={handleMouseLeave}
            {...props}
        >
            {list.map((k) => {
                const [corner, mod] = k.split("-");
                return <Cross key={k} on={hover || mod === "accent"} pos={CROSS_POS[corner]} />;
            })}
            {children}
        </div>
    );
}

export function SectionDivider() {
    const bp = useBP();

    return (
        <div>
            <div className="rule-h" />
            <div
                className="grid-frame"
                style={{
                    ...COL,
                    ...(bp === 0 ? MOBW : null),
                    height: 32,
                    backgroundImage: "var(--pinstripe)",
                    backgroundRepeat: "repeat",
                }}
            />
            <div className="rule-h" />
        </div>
    );
}

export function SectionHeader({
    eyebrow,
    title,
    sub,
}: {
    eyebrow: string;
    title: string;
    sub?: string;
}) {
    return (
        <div style={{ textAlign: "center", maxWidth: 640, margin: "0 auto clamp(36px,6vw,56px)" }}>
            <div
                className="mono-label"
                style={{
                    color: "var(--accent)",
                    marginBottom: 14,
                    fontFamily: "var(--font-mono)",
                    fontSize: 11,
                    letterSpacing: "0.05em",
                    textTransform: "uppercase",
                }}
            >
                {eyebrow.toUpperCase()}
            </div>
            <h2
                style={{
                    fontSize: "clamp(25px,5vw,34px)",
                    fontWeight: 500,
                    letterSpacing: "-0.02em",
                    lineHeight: 1.12,
                }}
            >
                {title}
            </h2>
            {sub && (
                <p
                    style={{
                        fontSize: "clamp(14.5px,2vw,16px)",
                        color: "var(--muted-foreground)",
                        lineHeight: 1.6,
                        marginTop: 12,
                        textWrap: "pretty",
                    }}
                >
                    {sub}
                </p>
            )}
        </div>
    );
}
