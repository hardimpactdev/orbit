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

        return $setting !== null ? $setting->value : $default;
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

    public static function getTerminal(): string
    {
        return static::get('terminal', 'Terminal');
    }

    public static function getTerminalOptions(): array
    {
        return [
            'Terminal' => 'Terminal',
            'iTerm' => 'iTerm2',
            'Ghostty' => 'Ghostty',
            'Warp' => 'Warp',
            'kitty' => 'Kitty',
            'Alacritty' => 'Alacritty',
            'Hyper' => 'Hyper',
        ];
    }

    public static function getSshPublicKey(): ?string
    {
        return static::get('ssh_public_key');
    }

    public static function setSshPublicKey(string $key): void
    {
        static::set('ssh_public_key', trim($key));
    }

    public static function getAvailableSshKeys(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? null);
        if (! $home) {
            return [];
        }

        $sshDir = $home.'/.ssh';
        if (! is_dir($sshDir)) {
            return [];
        }

        $keys = [];
        $patterns = ['*.pub'];

        foreach ($patterns as $pattern) {
            $files = glob($sshDir.'/'.$pattern);
            foreach ($files as $file) {
                $content = file_get_contents($file);
                if ($content && str_starts_with($content, 'ssh-')) {
                    $keys[basename($file)] = [
                        'path' => $file,
                        'content' => trim($content),
                        'type' => explode(' ', $content)[0],
                    ];
                }
            }
        }

        return $keys;
    }
}
