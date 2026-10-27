import type { InsightsDay, InsightsWeek } from "../api/insights";
import { formatCount } from "./insights-format";

const WIDTH = 600;
const HEIGHT = 120;
const PAD = 8;

/** Tokens per merged PR for each of the 12 weeks. */
export function WeeklyTrend({ weeks }: { weeks: readonly InsightsWeek[] }) {
    const values = weeks.map((week) => week.tokens_per_merged_pr);
    const max = Math.max(1, ...values.filter((value): value is number => value !== null));
    // Each week owns one slot; its point sits in the middle.
    const slot = (WIDTH - PAD * 2) / Math.max(1, weeks.length);
    const x = (index: number) => PAD + (index + 0.5) * slot;
    const y = (value: number) => HEIGHT - PAD - (value / max) * (HEIGHT - PAD * 2);
    // Weeks without a merge break the line rather than dropping to zero.
    const segments: string[][] = [[]];
    values.forEach((value, index) => {
        if (value === null) segments.push([]);
        else segments.at(-1)?.push(`${x(index)},${y(value)}`);
    });

    return (
        <figure data-testid="insights-weekly">
            <figcaption className="text-dim">
                Tokens per merged PR, weekly · peak {formatCount(max)}
            </figcaption>
            <svg
                viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                className="block h-auto w-full"
                role="img"
                aria-label="Weekly tokens per merged PR"
            >
                <line
                    x1={PAD}
                    x2={WIDTH - PAD}
                    y1={HEIGHT - PAD}
                    y2={HEIGHT - PAD}
                    stroke="var(--line)"
                />
                {segments
                    .filter((points) => points.length > 0)
                    .map((points) => (
                        <polyline
                            key={points[0]}
                            points={points.join(" ")}
                            fill="none"
                            stroke="var(--cyan)"
                            strokeWidth={2}
                        />
                    ))}
                {values.map((value, index) =>
                    value === null ? null : (
                        <circle
                            key={weeks[index]?.week_start}
                            cx={x(index)}
                            cy={y(value)}
                            r={3}
                            fill="var(--cyan)"
                        >
                            <title>{`${weeks[index]?.week_start}: ${formatCount(value)}`}</title>
                        </circle>
                    ),
                )}
            </svg>
            <div className="flex justify-between text-dim">
                <span>{weeks[0]?.week_start}</span>
                <span>{weeks.at(-1)?.week_start}</span>
            </div>
        </figure>
    );
}

/** Merged and not merged tasks for each day of the period. */
export function DailyOutcomes({ days }: { days: readonly InsightsDay[] }) {
    const max = Math.max(1, ...days.map((day) => day.merged + day.not_merged));
    const slot = (WIDTH - PAD * 2) / Math.max(1, days.length);
    const bar = Math.max(2, slot * 0.7);
    const scale = (count: number) => (count / max) * (HEIGHT - PAD * 2);

    return (
        <figure data-testid="insights-daily">
            <figcaption className="text-dim">
                Daily outcomes · <span className="text-green">merged</span> ·{" "}
                <span className="text-red">not merged</span>
            </figcaption>
            <svg
                viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                className="block h-auto w-full"
                role="img"
                aria-label="Daily merged and not merged tasks"
            >
                <line
                    x1={PAD}
                    x2={WIDTH - PAD}
                    y1={HEIGHT - PAD}
                    y2={HEIGHT - PAD}
                    stroke="var(--line)"
                />
                {days.map((day, index) => {
                    const left = PAD + index * slot + (slot - bar) / 2;
                    const merged = scale(day.merged);
                    const failed = scale(day.not_merged);
                    return (
                        <g key={day.date}>
                            <title>{`${day.date}: ${day.merged} merged, ${day.not_merged} not merged`}</title>
                            <rect
                                x={left}
                                y={HEIGHT - PAD - merged}
                                width={bar}
                                height={merged}
                                fill="var(--green)"
                            />
                            <rect
                                x={left}
                                y={HEIGHT - PAD - merged - failed}
                                width={bar}
                                height={failed}
                                fill="var(--red)"
                                opacity={0.7}
                            />
                        </g>
                    );
                })}
            </svg>
            <div className="flex justify-between text-dim">
                <span>{days[0]?.date}</span>
                <span>{days.at(-1)?.date}</span>
            </div>
        </figure>
    );
}
