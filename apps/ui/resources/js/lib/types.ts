import type { InertiaLinkProps } from "@inertiajs/react";
import type { LucideIcon } from "lucide-react";
import type { ReactNode } from "react";

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
};

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps["href"]>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps["href"]>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AppVariant = "header" | "sidebar";

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
