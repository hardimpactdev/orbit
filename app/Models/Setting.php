<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::find($key);
        return $setting?->value ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    public static function getEditor(): array
    {
        return [
            'scheme' => static::get('editor_scheme', 'cursor'),
            'name' => static::get('editor_name', 'Cursor'),
        ];
    }

    public static function getEditorOptions(): array
    {
        return [
            'cursor' => 'Cursor',
            'vscode' => 'VS Code',
            'vscode-insiders' => 'VS Code Insiders',
            'windsurf' => 'Windsurf',
            'antigravity' => 'Antigravity',
            'zed' => 'Zed',
        ];
    }
}
