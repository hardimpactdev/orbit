<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Support\ValidatedData;
use Illuminate\Validation\Validator;

final class SignDirectCertificatesRequest extends OperationPeerRequest
{
    public function authorize(): bool
    {
        $this->peerNodeId();

        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'run_id' => ['required', 'uuid'],
            'restoring' => ['sometimes', 'boolean:strict'],
            'requests' => ['required', 'array', 'list', 'min:1', 'max:256'],
            'requests.*' => ['required', 'array:node_id,scope,state,request_base64'],
            'requests.*.node_id' => ['required', 'integer:strict', 'min:1'],
            'requests.*.scope' => ['required', 'string', 'max:100', 'regex:/\A[a-z][a-z0-9-]*\z/D'],
            'requests.*.state' => ['required', 'in:request,current'],
            'requests.*.request_base64' => ['required_if:requests.*.state,request', 'prohibited_if:requests.*.state,current', 'string', 'max:24576'],
        ];
    }

    /** @return list<array{node_id:int,scope:string,state:string,request_base64?:string}> */
    public function certificateRequests(): array
    {
        $requests = $this->validated('requests');
        if (! is_array($requests) || ! array_is_list($requests)) {
            throw new \InvalidArgumentException('Certificate requests must be a list.');
        }
        $result = [];
        foreach ($requests as $request) {
            $row = ValidatedData::object($request);
            $result[] = ['node_id' => ValidatedData::integer($row['node_id']), 'scope' => ValidatedData::string($row['scope']),
                'state' => ValidatedData::string($row['state']),
                ...(isset($row['request_base64']) ? ['request_base64' => ValidatedData::string($row['request_base64'])] : [])];
        }

        return $result;
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['run_id', 'requests', 'restoring']) !== []) {
                $validator->errors()->add('request', 'Unexpected request fields.');
            }
        }];
    }
}
