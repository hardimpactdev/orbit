/** Immutable first leaf per Node; every leaf remains in that Node's child input. */
export function nodeCertificateGroups(list: string, property?: "leaf") {
    const field = property ? "." + property : "";
    return `${list}.filter(item,${list}.filter(first,first${field}.node_id == item${field}.node_id)[0]${field}.scope == item${field}.scope)`;
}
