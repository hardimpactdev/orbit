import { z } from "npm:zod@4.4.3";
import { type GatewayClient, OperationSchema } from "./gateway.ts";
import { RowSchema } from "./development-policy.ts";
import {
    isAutomaticDeployment,
    type Operation,
    PolicyError,
} from "./policy.ts";

/** Selected only by the development entry point; production retains its original decoder and routes. */
export function developmentAdmissions(client: GatewayClient) {
    const ReceiptSchema = z.object({
        request_key: z.string(),
        found: z.boolean(),
        operation: OperationSchema.nullable(),
        deployment: RowSchema.nullable(),
    });
    const receiptOperation = (
        instance: number,
        commit: string,
        key: string,
        value: unknown,
        resolving: boolean,
    ): Operation | null => {
        const parsed = ReceiptSchema.safeParse(value);
        if (!parsed.success) {
            throw new PolicyError(
                resolving
                    ? "automatic.outcome_unknown"
                    : "gateway.read_unavailable",
                "The development admission returned no readable receipt.",
            );
        }
        const receipt = parsed.data, op = receipt.operation;
        if (
            receipt.request_key !== key || receipt.found !== (op !== null) ||
            op && (op.target_type !== "instance" || op.target_id !== instance ||
                    op.kind !== "instance.deploy" ||
                    op.input.commit !== commit ||
                    !isAutomaticDeployment(op) || !op.input.caller_node_id)
        ) {
            throw new PolicyError(
                "gateway.read_unavailable",
                "The development receipt identity does not match.",
            );
        }
        if (resolving && !op) {
            throw new PolicyError(
                "automatic.outcome_unknown",
                "Admission resolution did not return its operation.",
            );
        }
        return op;
    };
    const recover = async <T>(
        read: () => Promise<T>,
        original: () => Promise<T>,
    ): Promise<T> => {
        try {
            return await read();
        } catch (error) {
            if (
                !(error instanceof PolicyError) ||
                error.code !== "operation.idempotency_conflict"
            ) throw error;
            return await original();
        }
    };
    return {
        checks: (id: number, commit: string, name: string) =>
            client.checks(id, commit, name),
        operation: (id: number) => client.operation(id),
        deploy: async (
            id: number,
            commit: string,
            key: string,
        ): Promise<Operation> => {
            const parsed = OperationSchema.safeParse(
                await client.request(
                    "POST",
                    `/api/v1/instances/${id}/deploy/automatic/development`,
                    { commit },
                    key,
                ),
            );
            if (
                !parsed.success || parsed.data.target_type !== "instance" ||
                parsed.data.target_id !== id ||
                parsed.data.kind !== "instance.deploy" ||
                parsed.data.input.commit !== commit ||
                !isAutomaticDeployment(parsed.data)
            ) {
                throw new PolicyError(
                    "automatic.outcome_unknown",
                    "The development start returned an unreadable operation.",
                );
            }
            return parsed.data;
        },
        receipt: (
            id: number,
            commit: string,
            key: string,
        ): Promise<Operation | null> =>
            recover(
                async () =>
                    receiptOperation(
                        id,
                        commit,
                        key,
                        await client.request(
                            "GET",
                            `/api/v1/instances/${id}/deploy/automatic/development/receipt?request_key=${
                                encodeURIComponent(key)
                            }&commit=${encodeURIComponent(commit)}`,
                        ),
                        false,
                    ),
                async () =>
                    receiptOperation(
                        id,
                        commit,
                        key,
                        await client.request(
                            "GET",
                            `/api/v1/instances/${id}/deploy/automatic/receipt?request_key=${
                                encodeURIComponent(key)
                            }&commit=${encodeURIComponent(commit)}`,
                        ),
                        false,
                    ),
            ),
        resolve: (
            id: number,
            commit: string,
            key: string,
        ): Promise<Operation> =>
            recover(
                async () =>
                    receiptOperation(
                        id,
                        commit,
                        key,
                        await client.request(
                            "POST",
                            `/api/v1/instances/${id}/deploy/automatic/development/resolve`,
                            { request_key: key, commit },
                        ),
                        true,
                    )!,
                async () =>
                    receiptOperation(
                        id,
                        commit,
                        key,
                        await client.request(
                            "POST",
                            `/api/v1/instances/${id}/deploy/automatic/resolve`,
                            { request_key: key, commit },
                        ),
                        true,
                    )!,
            ) as Promise<Operation>,
    };
}
