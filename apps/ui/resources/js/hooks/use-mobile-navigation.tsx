import { useCallback } from "react";
import { useSidebar } from "@/components/ui/sidebar";

export function useMobileNavigation() {
    const { setOpenMobile } = useSidebar();

    return useCallback(() => {
        setOpenMobile(false);
    }, [setOpenMobile]);
}
