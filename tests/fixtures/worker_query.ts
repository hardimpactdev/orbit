/** Existing fixture attributes wrapped in native query records. */
export function recordQuery<P extends string>(
  query: (predicate: P) => Promise<unknown[]>,
): (predicate: P, select?: string) => Promise<unknown[]> {
  return async (predicate, select) => {
    // Local nested behavior fixtures still use native local projections.
    if (select !== undefined) return await query(predicate);
    return (await query(predicate)).map((value) => ({
      attributes: typeof value === "string" ? { workItem: value } : value,
      content: value,
    }));
  };
}
