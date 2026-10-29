<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-type Child array{key:string,kind:string,node_id:int,input:array<string,mixed>,phase?:'before'|'after'}
 * @phpstan-type Command array{name:string,input:array<string,mixed>,transport?:string,protected?:list<string>,backend?:string,expect_empty?:bool,refusal?:string,redactions?:list<string>,resumable?:bool,finish_after_effect?:bool,cleanup?:bool,export_intent_id?:int,incoming_step?:string}
 * @phpstan-type Projection array{children?:list<Child>,created_connection?:int,attach_instance?:int|null,clone?:bool,drop_users?:list<string>,delete_connections?:list<int>,delete_server?:bool,process_id?:int|null,upsert_user?:array{database_connection_id:int,username:string,password:string,privileges:string,replication:bool,created_by:string|null},server?:array<string,mixed>,process?:array<string,mixed>,adopted_connection?:string|null}
 * Private, immutable command input. Operation owns all execution state and receipts.
 *
 * @property int $id
 * @property string $target_type
 * @property int $target_id
 * @property int $node_id
 * @property array{commands:array<string,Command>,projection:Projection,request?:array<string,mixed>,record?:array<string,mixed>,admission?:array{kind:string,input:array<string,mixed>}} $payload
 */
final class DatabaseExecutionIntent extends Model
{
    public const int RETRY_HOURS = 24;

    protected $guarded = [];

    protected $hidden = ['payload'];

    public static function recorded(mixed $id): self
    {
        return self::query()->findOrFail(ValidatedData::integer($id));
    }

    protected function casts(): array
    {
        return ['target_id' => 'integer', 'node_id' => 'integer', 'payload' => 'encrypted:array'];
    }
}
