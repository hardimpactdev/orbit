<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class CliInstallService
{
    protected string $cliPath;
    protected string $targetPath = '/usr/local/bin/orbit';
    
    public function __construct()
    {
        // Path to bundled CLI in app resources
        $this->cliPath = base_path('bin/orbit.phar');
    }
    
    public function isInstalled(): bool
    {
        return file_exists($this->targetPath) && is_executable($this->targetPath);
    }
    
    public function getBundledCliPath(): string
    {
        return $this->cliPath;
    }
    
    public function install(): bool
    {
        if (!file_exists($this->cliPath)) {
            Log::error('CLI binary not found at: ' . $this->cliPath);
            return false;
        }
        
        // Create install script
        $script = "#!/bin/bash\n";
        $script .= "cp \"{$this->cliPath}\" \"{$this->targetPath}\"\n";
        $script .= "chmod +x \"{$this->targetPath}\"\n";
        
        $tempScript = sys_get_temp_dir() . '/orbit-install.sh';
        file_put_contents($tempScript, $script);
        chmod($tempScript, 0755);
        
        // Use osascript to run with admin privileges
        $command = sprintf(
            'osascript -e \'do shell script "%s" with administrator privileges\'',
            $tempScript
        );
        
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        @unlink($tempScript);
        
        if ($returnCode === 0) {
            Log::info('CLI installed successfully to ' . $this->targetPath);
            return true;
        }
        
        Log::error('CLI installation failed with code: ' . $returnCode);
        return false;
    }
    
    public function getVersion(): ?string
    {
        if (!$this->isInstalled()) {
            return null;
        }
        
        $output = [];
        exec($this->targetPath . ' --version 2>/dev/null', $output);
        
        return $output[0] ?? null;
    }
}
