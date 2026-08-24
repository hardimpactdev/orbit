<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('provides the shadcn sonner toaster component', function () {
    $toaster = File::get(resource_path('js/components/ui/sonner.tsx'));

    expect($toaster)
        ->toContain('import { useTheme } from "next-themes";')
        ->toContain('import { Toaster as Sonner, type ToasterProps } from "sonner";')
        ->toContain('function Toaster({ ...props }: ToasterProps)')
        ->toContain('const { theme = "system" } = useTheme();')
        ->toContain('className="toaster group"')
        ->toContain('"--normal-bg": "var(--popover)"')
        ->toContain('toast: "cn-toast"')
        ->toContain('export { Toaster };');
});

it('does not install a default app layout for the toaster', function () {
    $app = File::get(resource_path('js/app.tsx'));

    expect($app)
        ->toContain('default:')
        ->toContain('return null;')
        ->not->toContain('AppLayout')
        ->not->toContain('AppToaster');
});
