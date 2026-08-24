import React from "react";

export const ICONS: Record<string, string> = {
    github: '<path fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/>',
    "layout-grid":
        '<rect width="7" height="7" x="3" y="3" rx="1" fill="var(--ic-bg, transparent)"></rect><rect width="7" height="7" x="14" y="3" rx="1" fill="var(--ic-bg, transparent)"></rect><rect width="7" height="7" x="14" y="14" rx="1" fill="var(--ic-bg, transparent)"></rect><rect width="7" height="7" x="3" y="14" rx="1" fill="var(--ic-bg, transparent)"></rect>',
    "chevrons-up-down":
        '<path d="m7 15 5 5 5-5"></path><path style="stroke:var(--ic-a, currentColor)" d="m7 9 5-5 5 5"></path>',
    "chevron-right": '<path d="m9 18 6-6-6-6"></path>',
    "chevron-down": '<path d="m6 9 6 6 6-6"></path>',
    monitor:
        '<rect width="20" height="14" x="2" y="3" rx="2" fill="var(--ic-bg, transparent)"></rect><line x1="8" x2="16" y1="21" y2="21"></line><line style="stroke:var(--ic-a, currentColor)" x1="12" x2="12" y1="17" y2="21"></line>',
    moon: '<path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401" fill="var(--ic-bg, transparent)"></path>',
    sun: '<circle style="stroke:var(--ic-a, currentColor)" cx="12" cy="12" r="4" fill="var(--ic-bg, transparent)"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="m4.93 4.93 1.41 1.41"></path><path d="m17.66 17.66 1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="m6.34 17.66-1.41 1.41"></path><path d="m19.07 4.93-1.41 1.41"></path>',
    eye: '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0" fill="var(--ic-bg, transparent)"></path><circle style="stroke:var(--ic-a, currentColor)" cx="12" cy="12" r="3" fill="var(--ic-bg, transparent)"></circle>',
    "eye-off":
        '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"></path><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"></path><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"></path><path style="stroke:var(--ic-a, currentColor)" d="m2 2 20 20"></path>',
    "lock-keyhole":
        '<circle style="stroke:var(--ic-a, currentColor)" cx="12" cy="16" r="1"></circle><rect x="3" y="10" width="18" height="12" rx="2" fill="var(--ic-bg, transparent)"></rect><path d="M7 10V7a5 5 0 0 1 10 0v3"></path>',
    "refresh-cw":
        '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path><path d="M21 3v5h-5"></path><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path><path style="stroke:var(--ic-a, currentColor)" d="M8 16H3v5"></path>',
    "shield-check":
        '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" fill="var(--ic-bg, transparent)"></path><path style="stroke:var(--ic-a, currentColor)" d="m9 12 2 2 4-4"></path>',
    "book-open":
        '<path d="M12 5v16"></path><path style="stroke:var(--ic-a, currentColor);fill:var(--ic-bg, transparent)" d="M20.001 19A2 2 0 0022 17V5a2 2 0 00-1.999-2L16 3.002A5 5 0 0012 5a5 5 0 00-4-2H4a2 2 0 00-2 2v12a2 2 0 001.999 2H8a5 5 0 014 2 5 5 0 014-2z"></path>',
    folder: '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z" fill="var(--ic-bg, transparent)"></path>',
    menu: '<path d="M4 5h16"></path><path d="M4 12h16"></path><path style="stroke:var(--ic-a, currentColor)" d="M4 19h16"></path>',
    search: '<path d="m21 21-4.34-4.34"></path><circle style="stroke:var(--ic-a, currentColor);fill:var(--ic-bg, transparent)" cx="11" cy="11" r="8"></circle>',
    "log-out":
        '<path d="m16 17 5-5-5-5"></path><path d="M21 12H9"></path><path style="stroke:var(--ic-a, currentColor)" d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>',
    settings:
        '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915" fill="var(--ic-bg, transparent)"></path><circle style="stroke:var(--ic-a, currentColor)" cx="12" cy="12" r="3"></circle>',
    "loader-circle": '<path d="M21 12a9 9 0 1 1-6.219-8.56"></path>',
    "scan-line":
        '<path d="M3 7V5a2 2 0 0 1 2-2h2"></path><path d="M17 3h2a2 2 0 0 1 2 2v2"></path><path d="M21 17v2a2 2 0 0 1-2 2h-2"></path><path d="M7 21H5a2 2 0 0 1-2-2v-2"></path><path style="stroke:var(--ic-a, currentColor)" d="M7 12h10"></path>',
    "key-round":
        '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z" fill="var(--ic-bg, transparent)"></path><circle style="stroke:var(--ic-a, currentColor);fill:var(--ic-a, currentColor)" cx="16.5" cy="7.5" r=".5"></circle>',
    "rotate-ccw":
        '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path style="stroke:var(--ic-a, currentColor)" d="M3 3v5h5"></path>',
    x: '<path d="M18 6 6 18"></path><path style="stroke:var(--ic-a, currentColor)" d="m6 6 12 12"></path>',
    check: '<path d="M20 6 9 17l-5-5"></path>',
    ellipsis:
        '<circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle style="stroke:var(--ic-a, currentColor)" cx="5" cy="12" r="1"></circle>',
    "panel-left":
        '<rect width="18" height="18" x="3" y="3" rx="2" fill="var(--ic-bg, transparent)"></rect><path style="stroke:var(--ic-a, currentColor)" d="M9 3v18"></path>',
    rocket: '<path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"></path><path style="stroke:var(--ic-a, currentColor)" d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09" fill="var(--ic-bg, transparent)"></path><path d="M9 12a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.4 22.4 0 0 1-4 2z"></path><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 .05 5 .05"></path>',
    terminal:
        '<path d="M12 19h8"></path><path style="stroke:var(--ic-a, currentColor)" d="m4 17 6-6-6-6"></path>',
    zap: '<path d="M15.914 4a1.5 1.5 0 00-2.474-1.561l-9 9A1.5 1.5 0 005.5 14h4.002a.5.5 0 01.471.666L8.086 20a1.5 1.5 0 002.475 1.56l9-9A1.5 1.5 0 0018.5 10h-3.997a.5.5 0 01-.472-.667z" fill="var(--ic-bg, transparent)"></path><path d="M13.2 5.2 9.2 11.6 13.3 12.4 10.8 18.7" style="stroke:var(--ic-a, currentColor)" stroke-width="1" stroke-linejoin="round" fill="none"></path>',
    globe: '<circle cx="12" cy="12" r="10" fill="var(--ic-bg, transparent)"></circle><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path><path style="stroke:var(--ic-a, currentColor)" d="M2 12h20"></path>',
    package:
        '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z" fill="var(--ic-bg, transparent)"></path><path d="M12 22V12"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><path style="stroke:var(--ic-a, currentColor)" d="m7.5 4.27 9 5.15"></path>',
    layers: '<path style="stroke:var(--ic-a, currentColor);fill:var(--ic-bg, transparent)" d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"></path><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"></path><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"></path>',
    blocks: '<path style="stroke:var(--ic-a, currentColor)" d="M10 22V7a1 1 0 0 0-1-1H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5a1 1 0 0 0-1-1H2"></path><rect x="14" y="2" width="8" height="8" rx="1" fill="var(--ic-bg, transparent)"></rect>',
    languages:
        '<path d="m5 8 6 6"></path><path d="m4 14 6-6 2-3"></path><path d="M2 5h12"></path><path d="M7 2h1"></path><path d="m22 22-5-10-5 10"></path><path style="stroke:var(--ic-a, currentColor)" d="M14 18h6"></path>',
    palette:
        '<path d="M12 22a1 1 0 0 1 0-20 10 9 0 0 1 10 9 5 5 0 0 1-5 5h-2.25a1.75 1.75 0 0 0-1.4 2.8l.3.4a1.75 1.75 0 0 1-1.4 2.8z" fill="var(--ic-bg, transparent)"></path><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"></circle><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"></circle><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"></circle><circle style="stroke:var(--ic-a, currentColor);fill:var(--ic-a, currentColor)" cx="8.5" cy="7.5" r=".5"></circle>',
    "git-branch":
        '<path d="M15 6a9 9 0 0 0-9 9V3"></path><circle cx="18" cy="6" r="3" fill="var(--ic-bg, transparent)"></circle><circle style="stroke:var(--ic-a, currentColor)" cx="6" cy="18" r="3" fill="var(--ic-bg, transparent)"></circle>',
    "arrow-right":
        '<path d="M5 12h14"></path><path style="stroke:var(--ic-a, currentColor)" d="m12 5 7 7-7 7"></path>',
    "arrow-up-right":
        '<path d="M7 7h10v10"></path><path style="stroke:var(--ic-a, currentColor)" d="M7 17 17 7"></path>',
    plus: '<path d="M5 12h14"></path><path style="stroke:var(--ic-a, currentColor)" d="M12 5v14"></path>',
    user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" fill="var(--ic-bg, transparent)"></path><circle style="stroke:var(--ic-a, currentColor);fill:var(--ic-bg, transparent)" cx="12" cy="7" r="4"></circle>',
    "circle-check":
        '<circle cx="12" cy="12" r="10" fill="var(--ic-bg, transparent)"></circle><path style="stroke:var(--ic-a, currentColor)" d="m9 12 2 2 4-4"></path>',
    info: '<circle cx="12" cy="12" r="10" fill="var(--ic-bg, transparent)"></circle><path d="M12 16v-4"></path><path style="stroke:var(--ic-a, currentColor)" d="M12 8h.01"></path>',
    "triangle-alert":
        '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3" fill="var(--ic-bg, transparent)"></path><path d="M12 9v4"></path><path style="stroke:var(--ic-a, currentColor)" d="M12 17h.01"></path>',
    database:
        '<ellipse style="stroke:var(--ic-a, currentColor);fill:var(--ic-bg, transparent)" cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M3 5V19A9 3 0 0 0 21 19V5" fill="var(--ic-bg, transparent)"></path><path d="M3 12A9 3 0 0 0 21 12"></path>',
    code: '<path d="m16 18 6-6-6-6"></path><path style="stroke:var(--ic-a, currentColor)" d="m8 6-6 6 6 6"></path>',
    copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2" fill="var(--ic-bg, transparent)"></rect><path style="stroke:var(--ic-a, currentColor)" d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"></path>',
    bell: '<path d="M10.268 21a2 2 0 0 0 3.464 0"></path><path style="stroke:var(--ic-a, currentColor);fill:var(--ic-bg, transparent)" d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"></path>',
    sparkles:
        '<path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z" fill="var(--ic-bg, transparent)"></path><path d="M20 2v4"></path><path d="M22 4h-4"></path><circle style="stroke:var(--ic-a, currentColor)" cx="4" cy="20" r="2"></circle>',
    mail: '<rect width="20" height="16" x="2" y="4" rx="2" fill="var(--ic-bg, transparent)"></rect><path style="stroke:var(--ic-a, currentColor)" d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>',
    filament:
        '<path fill="currentColor" stroke="none" transform="translate(-7.136 -64.814) scale(6.032)" d="M3.578 11.160l0 0-.453.016-.058.212a.08.08 0 0 0 .03.074l.314.219-.366.024c-.03.002-.053.026-.06.058l-.03.137a.08.08 0 0 0 .03.074l.314.218-.366.024c-.03.002-.053.026-.06.058l-.029.135a.08.08 0 0 0 .03.075l.314.218-.367.025c-.028.002-.053.025-.06.057-.011.046-.02.092-.029.138a.08.08 0 0 0 .03.073l.314.218-.366.025c-.03.002-.053.025-.06.058l-.03.137a.08.08 0 0 0 .032.073l.313.22-.367.023c-.028.002-.053.025-.06.057l-.127.504h.41l.065-.274.513-.045c.03-.002.054-.025.06-.058l.03-.136a.08.08 0 0 0-.031-.074l-.314-.219.367-.024c.029-.002.053-.026.06-.058.011-.045.019-.091.028-.136a.08.08 0 0 0-.03-.074l-.313-.219.366-.025c.03 0 .054-.025.06-.057l.03-.137a.08.08 0 0 0-.03-.074l-.315-.218.367-.025c.029-.002.053-.025.06-.057l.028-.128v-.009a.08.08 0 0 0-.03-.074l-.313-.218.367-.024c.029-.002.053-.025.06-.057l.028-.128v-.01a.08.08 0 0 0-.03-.073l-.415-.292Z"></path>',
};

export interface LaunchIconProps extends React.SVGProps<SVGSVGElement> {
    name: string;
    size?: number;
    accent?: boolean | string;
    strokeWidth?: number;
    spin?: boolean;
}

export function LaunchIcon({
    name,
    size = 16,
    accent = false,
    strokeWidth,
    spin = false,
    style,
    className,
    ...rest
}: LaunchIconProps) {
    const inner = ICONS[name] || "";
    const isAccent = Boolean(accent);
    const accColor = typeof accent === "string" && accent !== "duotone" ? accent : "var(--accent)";

    const s: React.CSSProperties = {
        flex: "none",
        ...(spin ? { animation: "spin 1s linear infinite" } : {}),
        ...(isAccent
            ? ({
                  "--ic-a": accColor,
                  ...(accent === "duotone" ? { "--ic-bg": "var(--accent-dim)" } : {}),
              } as any)
            : {}),
        ...style,
    };

    return (
        <svg
            className={className}
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={strokeWidth ?? 24 / size}
            strokeLinecap="round"
            strokeLinejoin="round"
            style={s}
            dangerouslySetInnerHTML={{ __html: inner }}
            {...rest}
        />
    );
}
