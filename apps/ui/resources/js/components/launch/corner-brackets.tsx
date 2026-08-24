import React from "react";

export type CornerPosition = "tl" | "tr" | "bl" | "br";

export interface CornerBracketsProps {
    readonly corners?: readonly CornerPosition[];
    readonly color?: string;
    readonly size?: number;
    readonly offset?: number;
    readonly radius?: number;
    readonly active?: boolean;
    readonly zIndex?: number;
    readonly className?: string;
    readonly style?: React.CSSProperties;
}

const ALL_CORNERS: readonly CornerPosition[] = ["tl", "tr", "bl", "br"] as const;

export function CornerBrackets({
    corners = ALL_CORNERS,
    color = "var(--accent)",
    size = 5,
    offset = 0,
    radius = 2,
    active = true,
    zIndex = 3,
    className,
    style,
}: CornerBracketsProps) {
    if (!active) {
        return null;
    }

    const borderVal = `1px solid ${color}`;

    return (
        <>
            {corners.map((pos) => {
                const cornerStyle: React.CSSProperties = {
                    position: "absolute",
                    width: size,
                    height: size,
                    zIndex,
                    pointerEvents: "none",
                    transition:
                        "opacity var(--dur-fast) var(--ease-out), border-color var(--dur-fast) var(--ease-out)",
                    ...style,
                };

                if (pos[0] === "t") {
                    cornerStyle.top = offset;
                    cornerStyle.borderTop = borderVal;
                } else {
                    cornerStyle.bottom = offset;
                    cornerStyle.borderBottom = borderVal;
                }

                if (pos[1] === "l") {
                    cornerStyle.left = offset;
                    cornerStyle.borderLeft = borderVal;
                } else {
                    cornerStyle.right = offset;
                    cornerStyle.borderRight = borderVal;
                }

                const radiusKey =
                    `border${pos[0] === "t" ? "Top" : "Bottom"}${pos[1] === "l" ? "Left" : "Right"}Radius` as keyof React.CSSProperties;
                (cornerStyle as Record<string, unknown>)[radiusKey] = radius;

                return (
                    <span key={pos} className={className} style={cornerStyle} aria-hidden="true" />
                );
            })}
        </>
    );
}

export function CornerOrnament({
    at,
    color = "var(--accent)",
    size = 5,
    offset = 0,
    radius = 2,
    active = true,
    zIndex = 3,
    className,
    style,
}: {
    readonly at: CornerPosition;
    readonly color?: string;
    readonly size?: number;
    readonly offset?: number;
    readonly radius?: number;
    readonly active?: boolean;
    readonly zIndex?: number;
    readonly className?: string;
    readonly style?: React.CSSProperties;
}) {
    return (
        <CornerBrackets
            corners={[at]}
            color={color}
            size={size}
            offset={offset}
            radius={radius}
            active={active}
            zIndex={zIndex}
            className={className}
            style={style}
        />
    );
}
