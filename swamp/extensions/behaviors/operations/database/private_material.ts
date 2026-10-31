/** Existing callback-local material contract used by remaining Database parents. */
export type PrivateMaterialUse = (
    use: (material: Record<string, unknown>) => Promise<void>,
) => Promise<void>;
