import React from "react";
import { CornerBrackets } from "@/components/launch/corner-brackets";
import { cn } from "@/lib/utils";

export interface LayerChipProps {
    readonly index: number;
    readonly name: string;
    readonly logo: string | null;
    readonly color: string;
    readonly isActive: boolean;
    readonly isDark: boolean;
    readonly width?: number | string;
    readonly padding?: string;
    readonly borderColor?: string;
    readonly backgroundColor?: string;
    readonly inactiveOpacity?: number;
    readonly className?: string;
    readonly style?: React.CSSProperties;
    readonly onClick?: () => void;
    readonly onKeyDown?: (e: React.KeyboardEvent<HTMLDivElement>) => void;
    readonly tabIndex?: number;
    readonly role?: string;
    readonly "aria-selected"?: boolean;
    readonly "aria-controls"?: string;
    readonly id?: string;
}

function hexToRgba(hex: string, alpha: number): string {
    const clean = hex.replace("#", "");
    const r = parseInt(clean.substring(0, 2), 16);
    const g = parseInt(clean.substring(2, 4), 16);
    const b = parseInt(clean.substring(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

export const LayerChip = React.forwardRef<HTMLDivElement, LayerChipProps>(function LayerChip(
    {
        index,
        name,
        logo,
        color,
        isActive,
        isDark,
        width,
        padding = "0 14px",
        borderColor,
        backgroundColor,
        inactiveOpacity = 1,
        className,
        style,
        onClick,
        onKeyDown,
        tabIndex,
        role,
        ...props
    },
    ref,
) {
    const activeBorder = isDark ? hexToRgba(color, 0.18) : color;
    const activeBg = isDark ? hexToRgba(color, 0.12) : color;
    const activeStripe = `url("data:image/svg+xml,${encodeURIComponent(
        `<svg xmlns='http://www.w3.org/2000/svg' width='4' height='4'><path d='M-1,1 l2,-2 M0,4 l4,-4 M3,5 l2,-2' stroke='${
            isDark ? hexToRgba(color, 0.22) : "rgba(255,255,255,0.25)"
        }' stroke-width='1'/></svg>`,
    )}")`;

    const textColor = isActive
        ? isDark
            ? "var(--foreground)"
            : "#ffffff"
        : "var(--muted-foreground)";

    const numColor = isActive ? (isDark ? color : "#ffffff") : "var(--faint-foreground)";

    const finalBorderColor = borderColor ?? (isActive ? activeBorder : "var(--grid-line)");
    const finalBg = backgroundColor ?? (isActive ? activeBg : "var(--surface-card)");

    const handleKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
        if (onKeyDown) {
            onKeyDown(e);
        } else if (onClick && (e.key === "Enter" || e.key === " ")) {
            e.preventDefault();
            onClick();
        }
    };

    return (
        <div
            ref={ref}
            role={role ?? (onClick ? "button" : undefined)}
            tabIndex={tabIndex ?? (onClick ? 0 : undefined)}
            onClick={onClick}
            onKeyDown={handleKeyDown}
            className={cn(
                "focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--primary)]",
                className,
            )}
            style={{
                position: "relative",
                height: 44,
                width: width ?? "100%",
                display: "flex",
                alignItems: "center",
                justifyContent: "space-between",
                padding,
                boxSizing: "border-box",
                cursor: onClick ? "pointer" : "default",
                border: "1px solid",
                borderColor: finalBorderColor,
                backgroundColor: finalBg,
                backgroundImage: isActive ? activeStripe : "none",
                borderRadius: 2,
                transition: "all var(--dur-fast) var(--ease-out)",
                boxShadow: isActive && isDark ? `0 0 26px ${hexToRgba(color, 0.25)}` : "none",
                opacity: isActive ? 1 : inactiveOpacity,
                ...style,
            }}
            {...props}
        >
            <CornerBrackets
                color={color}
                size={5}
                offset={-1}
                radius={2}
                active={isActive && isDark}
            />

            <span
                style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 8,
                    minWidth: 0,
                }}
            >
                {logo && (
                    <img
                        src={`/assets/logos/${logo}.svg`}
                        width="12"
                        height="12"
                        alt=""
                        style={{
                            flex: "none",
                            opacity: isActive ? 1 : 0.85,
                            filter: isActive && !isDark ? "brightness(0) invert(1)" : "none",
                            transition: "all var(--dur-fast) var(--ease-out)",
                        }}
                    />
                )}
                <span
                    className="mono-label"
                    style={{
                        fontSize: 10,
                        whiteSpace: "nowrap",
                        color: textColor,
                    }}
                >
                    {name}
                </span>
            </span>
            <span
                className="mono-label"
                style={{
                    fontSize: 10,
                    color: numColor,
                    transition: "color var(--dur-fast) var(--ease-out)",
                }}
            >
                0{index + 1}
            </span>
        </div>
    );
});
