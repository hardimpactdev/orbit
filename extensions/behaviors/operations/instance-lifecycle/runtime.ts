export type Receipt = Record<string, unknown>;
export type Completion = {
  status: "succeeded" | "failed" | "cancelled";
  result: Receipt;
  error_code: string | null;
};
export type Child = {
  key: string;
  kind: string;
  node_id: number;
  input: Receipt;
};
export interface Session {
  readonly id: number;
  readonly nodeId: number;
  readonly kind: string;
  readonly input: Receipt;
  run(key: string): Promise<Receipt>;
  child(child: Child): Promise<Receipt>;
}
