import React from "react";
import { LaunchIcon } from "@/components/launch/icons";
import { Button, type ButtonProps } from "@/components/ui/button";

export function launchNow(): void {
    if (typeof document === "undefined") {
        return;
    }

    const terminalEl = document.getElementById("agent-terminal");
    if (terminalEl) {
        terminalEl.scrollIntoView({ behavior: "smooth" });
        const inputEl = terminalEl.querySelector<HTMLInputElement>("input, [tabindex='0']");
        inputEl?.focus({ preventScroll: true });
    }
}

export function LaunchNowButton({
    children,
    size = "lg",
    className,
    style,
    onClick,
    ...props
}: ButtonProps) {
    const handleClick = (e: React.MouseEvent<HTMLButtonElement>) => {
        launchNow();
        onClick?.(e);
    };

    return (
        <Button
            size={size}
            className={className}
            style={{ padding: "0 18px", ...style }}
            onClick={handleClick}
            {...props}
        >
            {children ?? (
                <>
                    Launch now <LaunchIcon name="arrow-right" size={15} />
                </>
            )}
        </Button>
    );
}
