/** Root actions stay unavailable until the shared local privilege API is approved. */
export function localRule(
  _input: Record<string, unknown>,
): Promise<unknown> {
  return Promise.reject(
    new Error("Firewall requires the approved local Node privilege boundary"),
  );
}
