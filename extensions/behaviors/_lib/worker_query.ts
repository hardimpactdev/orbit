/** Project only records returned through Swamp's worker capability checks. */
export async function queryWorkerData(
  context: { queryData: (predicate: string) => Promise<unknown[]> },
  predicate: string,
  field: "attributes" | "attributes.workItem" | "content",
): Promise<unknown[]> {
  const records = await context.queryData(predicate);
  return records.map((value) => {
    if (!value || typeof value !== "object") {
      throw new TypeError("invalid worker query record");
    }
    const record = value as {
      attributes?: Record<string, unknown>;
      content?: unknown;
    };
    return field === "attributes.workItem"
      ? record.attributes?.workItem
      : record[field];
  });
}
