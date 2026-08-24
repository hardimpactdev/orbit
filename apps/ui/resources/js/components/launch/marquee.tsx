import React from "react";
import { cn } from "@/lib/utils";

export interface MarqueeProps extends React.HTMLAttributes<HTMLDivElement> {
    readonly duration?: string;
    readonly fadeWidth?: number;
    readonly maskGradient?: string;
    readonly children: React.ReactNode;
}

const EDGE_BLURS: readonly [number, string][] = [
    [2, "#000 0%, rgba(0,0,0,0) 55%"],
    [5, "#000 0%, rgba(0,0,0,0) 35%"],
    [10, "#000 0%, rgba(0,0,0,0) 20%"],
] as const;

export function Marquee({
    duration = "50s",
    fadeWidth = 100,
    maskGradient = "linear-gradient(90deg, transparent, #000 12%, #000 88%, transparent)",
    children,
    className,
    style,
    ...props
}: MarqueeProps) {
    return (
        <div
            className={cn("relative overflow-hidden", className)}
            style={{
                background: "var(--marquee-bg)",
                WebkitMaskImage: maskGradient,
                maskImage: maskGradient,
                ...style,
            }}
            {...props}
        >
            <div
                style={{
                    display: "flex",
                    gap: 0,
                    width: "max-content",
                    animation: `marquee ${duration} linear infinite`,
                }}
            >
                {children}
            </div>

            {/* 3-layer progressive edge blur */}
            {(["left", "right"] as const).map((side) =>
                EDGE_BLURS.map(([blur, mask]) => (
                    <div
                        key={`${side}-${blur}`}
                        aria-hidden="true"
                        style={{
                            position: "absolute",
                            top: 0,
                            bottom: 0,
                            [side]: 0,
                            width: fadeWidth,
                            pointerEvents: "none",
                            backdropFilter: `blur(${blur}px)`,
                            WebkitBackdropFilter: `blur(${blur}px)`,
                            WebkitMaskImage: `linear-gradient(${side === "left" ? 90 : 270}deg, ${mask})`,
                            maskImage: `linear-gradient(${side === "left" ? 90 : 270}deg, ${mask})`,
                        }}
                    />
                )),
            )}
        </div>
    );
}
