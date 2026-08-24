import React from "react";
import { cn } from "@/lib/utils";
import { CornerBrackets } from "@/components/launch/corner-brackets";
import { LaunchIcon } from "@/components/launch/icons";

export const MARK_PATH =
    "M1223.92 273.679C1468.46 273.679 1697.08 341.835 1891.81 460.188C1637.3 634.573 1348.82 802.005 1163.83 908.931C916.024 1052.17 712.203 1131.33 606.343 1145.92C529.409 1156.53 450.213 1159.14 412.678 1159.78H0C168.776 645.252 652.957 273.679 1223.92 273.679ZM2017.53 547.199C2215.93 702.648 2367.57 915.041 2447.85 1159.78H1931.35C1893.1 1154.97 1689.32 1121.74 1733.9 963.989C1770.99 832.74 1858.5 719.437 2017.53 547.199ZM2498.81 3.28027C2513.55 -7.9855 2531.52 12.3198 2518.64 25.667L2195.58 360.422C2134.57 423.63 2074.17 485.11 2017.54 547.202C2017.54 547.202 2017.54 547.2 2017.54 547.196H2017.54C2017.43 547.111 2016.11 546.069 2013.87 544.317C2012.28 543.067 2010.22 541.455 2007.8 539.575C2001.44 534.625 1992.6 527.812 1983.29 520.8C1981.37 519.357 1979.43 517.904 1977.49 516.459C1974.6 514.306 1971.7 512.166 1968.85 510.089C1967.66 509.218 1966.48 508.358 1965.3 507.513C1963.87 506.476 1962.45 505.461 1961.06 504.475C1959.23 503.179 1957.46 501.931 1955.74 500.748C1954.71 500.033 1953.65 499.308 1952.56 498.57C1949.68 496.616 1946.64 494.587 1943.52 492.533C1943.34 492.418 1943.16 492.303 1942.99 492.188C1940.58 490.607 1938.12 489.01 1935.65 487.419C1931.07 484.472 1926.45 481.545 1922.01 478.76C1921.27 478.294 1920.54 477.829 1919.8 477.371C1919.18 476.983 1918.57 476.6 1917.96 476.219C1915.23 474.515 1912.6 472.884 1910.13 471.36C1909.54 470.991 1908.95 470.628 1908.37 470.271C1907.51 469.744 1906.68 469.231 1905.87 468.734C1905.32 468.394 1904.77 468.061 1904.24 467.736C1903.17 467.077 1902.14 466.45 1901.18 465.861C1901.13 465.832 1901.08 465.802 1901.04 465.772C1900.16 465.236 1899.33 464.732 1898.56 464.262C1897.73 463.761 1896.97 463.301 1896.28 462.883C1895.26 462.264 1894.4 461.738 1893.71 461.32C1892.49 460.585 1891.82 460.181 1891.8 460.174C1967.95 403.666 2085.55 319.259 2152.06 268.397C2251.9 192.051 2391.66 85.1981 2498.81 3.28027Z";

export const WORDMARK_PATHS = [
    "M0 2414.89V1684.89H125V2301.89H435V2414.89H0Z",
    "M613.609 2414.89L797.609 1684.89H958.609L1143.61 2414.89H1015.61L975.609 2237.89H781.609L741.609 2414.89H613.609ZM804.609 2135.89H952.609L908.609 1939.89C901.276 1907.23 894.943 1877.89 889.609 1851.89C884.276 1825.23 880.609 1805.89 878.609 1793.89C876.609 1805.89 872.943 1825.23 867.609 1851.89C862.276 1877.89 855.943 1906.89 848.609 1938.89L804.609 2135.89Z",
    "M1578.22 2424.89C1506.89 2424.89 1450.89 2405.56 1410.22 2366.89C1370.22 2327.56 1350.22 2206.89 1350.22 2206.89V1684.89H1476.22V2205.89C1476.22 2240.56 1484.89 2267.56 1502.22 2286.89C1519.55 2306.23 1544.89 2315.89 1578.22 2315.89C1610.89 2315.89 1635.89 2306.23 1653.22 2286.89C1671.22 2267.56 1680.22 2240.56 1680.22 2205.89V1684.89H1806.22V2206.89C1806.22 2274.23 1786.22 2327.56 1746.22 2366.89C1706.22 2405.56 1650.22 2424.89 1578.22 2424.89Z",
    "M2049.83 2414.89V1684.89H2202.83L2405.83 2274.89C2403.83 2253.56 2401.83 2228.56 2399.83 2199.89C2397.83 2170.56 2395.83 2140.89 2393.83 2110.89C2392.49 2080.89 2391.83 2054.23 2391.83 2030.89V1684.89H2505.83V2414.89H2352.83L2151.83 1824.89C2153.16 1844.23 2154.83 1867.56 2156.83 1894.89C2158.83 1922.23 2160.49 1950.23 2161.83 1978.89C2163.16 2007.56 2163.83 2033.89 2163.83 2057.89V2414.89H2049.83Z",
    "M2985.44 2424.89C2938.1 2424.89 2897.1 2416.23 2862.44 2398.89C2827.77 2380.89 2800.77 2355.89 2781.44 2323.89C2762.77 2291.23 2753.44 2252.89 2753.44 2208.89V1890.89C2753.44 1846.89 2762.77 1808.89 2781.44 1776.89C2800.77 1744.23 2827.77 1719.23 2862.44 1701.89C2897.1 1683.89 2938.1 1674.89 2985.44 1674.89C3032.1 1674.89 3072.77 1683.89 3107.44 1701.89C3142.1 1719.23 3169.1 1744.23 3188.44 1776.89C3207.77 1808.89 3217.44 1846.89 3217.44 1890.89H3091.44C3091.44 1856.89 3082.1 1830.89 3063.44 1812.89C3045.44 1794.23 3019.44 1784.89 2985.44 1784.89C2951.44 1784.89 2925.1 1794.23 2906.44 1812.89C2887.77 1830.89 2878.44 1856.89 2878.44 1890.89V2208.89C2878.44 2242.89 2887.77 2269.23 2906.44 2287.89C2925.1 2305.89 2951.44 2314.89 2985.44 2314.89C3019.44 2314.89 3045.44 2305.89 3063.44 2287.89C3082.1 2269.23 3091.44 2242.89 3091.44 2208.89H3217.44C3217.44 2252.89 3207.77 2291.23 3188.44 2323.89C3169.1 2355.89 3142.1 2380.89 3107.44 2398.89C3072.77 2416.23 3032.1 2424.89 2985.44 2424.89Z",
    "M3454.05 2414.89V1684.89H3579.05V1980.89H3775.05V1684.89H3900.05V2414.89H3775.05V2093.89H3579.05V2414.89H3454.05Z",
];

interface LogoProps extends React.SVGProps<SVGSVGElement> {
    variant?: "mark" | "wordmark" | "full";
    height?: number;
    width?: number;
}

export function Logo({ variant = "full", height = 24, className, style, ...props }: LogoProps) {
    if (variant === "mark") {
        return (
            <svg
                viewBox="0 0 2524 1160"
                height={height}
                width={height * (2524 / 1160)}
                fill="var(--accent)"
                className={cn("shrink-0", className)}
                style={style}
                {...props}
            >
                <path d={MARK_PATH} />
            </svg>
        );
    }

    if (variant === "wordmark") {
        return (
            <svg
                viewBox="0 1674.89 3900.05 750"
                height={height}
                width={height * (3900.05 / 750)}
                fill="currentColor"
                className={cn("shrink-0", className)}
                style={style}
                {...props}
            >
                {WORDMARK_PATHS.map((d, i) => (
                    <path key={i} d={d} />
                ))}
            </svg>
        );
    }

    return (
        <svg
            viewBox="0 0 6554.05 1160"
            height={height}
            width={height * (6554.05 / 1160)}
            className={cn("shrink-0", className)}
            style={style}
            {...props}
        >
            <path d={MARK_PATH} fill="var(--accent)" />
            <g fill="currentColor">
                {WORDMARK_PATHS.map((d, i) => (
                    <path key={i} d={d} transform="translate(2654, -1280)" />
                ))}
            </g>
        </svg>
    );
}

const TILE_STRIPE = `url("data:image/svg+xml,${encodeURIComponent(
    "<svg xmlns='http://www.w3.org/2000/svg' width='4' height='4'><path d='M-1,1 l2,-2 M0,4 l4,-4 M3,5 l2,-2' stroke='rgba(251,59,0,0.08)' stroke-width='1'/></svg>",
)}")`;

interface IconTileProps extends React.HTMLAttributes<HTMLDivElement> {
    icon?: React.ReactNode;
    name?: string;
    size?: "sm" | "md" | "lg" | "xl";
    accent?: boolean | string;
    glow?: boolean;
    corners?: boolean;
    active?: boolean;
}

export function IconTile({
    icon,
    name,
    size = "md",
    accent = true,
    glow = false,
    corners = true,
    active = false,
    className,
    style,
    ...props
}: IconTileProps) {
    const dims: Record<string, [number, number]> = {
        sm: [32, 16],
        md: [40, 20],
        lg: [48, 24],
        xl: [56, 28],
    };
    const [box, ic] = dims[size] || dims.md;
    const [hov, setHov] = React.useState(false);
    const on = hov || active;

    return (
        <div
            onMouseEnter={() => setHov(true)}
            onMouseLeave={() => setHov(false)}
            className={cn("shrink-0", className)}
            style={{
                position: "relative",
                width: box,
                height: box,
                borderRadius: 2,
                border: `1px solid ${on ? "var(--accent-dim)" : "var(--grid-line)"}`,
                backgroundColor: "var(--surface-raised)",
                backgroundImage: on ? TILE_STRIPE : "none",
                boxShadow: glow ? "var(--glow-accent)" : "none",
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                color: on ? "var(--accent)" : "var(--icon-muted)",
                transition:
                    "border-color var(--dur-fast) var(--ease-out), color var(--dur-fast) var(--ease-out)",
                flex: "none",
                ...style,
            }}
            {...props}
        >
            <CornerBrackets
                active={Boolean(corners)}
                color={on ? "var(--accent)" : "var(--border-strong)"}
                offset={-1}
                size={5}
                radius={2}
            />
            {icon ||
                (name ? (
                    <LaunchIcon name={name} size={ic} accent={on ? "duotone" : accent} />
                ) : null)}
        </div>
    );
}

interface ToastProps extends React.HTMLAttributes<HTMLDivElement> {
    variant?: "default" | "success" | "destructive";
    title: string;
    description?: string;
}

export function Toast({
    variant = "default",
    title,
    description,
    className,
    style,
    ...props
}: ToastProps) {
    return (
        <div
            className={cn(
                "flex items-center gap-3 rounded-lg border border-[var(--border-strong)] bg-[var(--card)] px-4 py-3 text-left font-sans text-xs text-[var(--foreground)] shadow-[var(--shadow-overlay)] backdrop-blur-md",
                className,
            )}
            style={style}
            {...props}
        >
            {variant === "success" && (
                <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-success/20 text-success">
                    <svg
                        className="size-3"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.5"
                    >
                        <polyline points="20 6 9 17 4 12" />
                    </svg>
                </span>
            )}
            <div>
                <div className="font-medium text-[var(--foreground)]">{title}</div>
                {description && (
                    <div className="mt-0.5 text-[var(--muted-foreground)]">{description}</div>
                )}
            </div>
        </div>
    );
}
