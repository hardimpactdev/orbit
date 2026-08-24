import type { InertiaLinkProps } from "@inertiajs/react";
import { type ClassValue, clsx } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(href: NonNullable<InertiaLinkProps["href"]>): string {
    if (typeof href === "string") {
        return href;
    }

    return href.url;
}
