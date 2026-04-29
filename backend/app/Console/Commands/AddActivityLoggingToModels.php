<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AddActivityLoggingToModels extends Command
{
    protected $signature = 'activity-log:add-to-models';
    protected $description = 'Add AutoLogsActivity trait to all models';

    public function handle()
    {
        $modelPath = app_path('Models');
        $files = File::files($modelPath);
        
        $excludeModels = [
            'PasswordResetToken.php',
            'EmailVerificationToken.php',
            'EmployeeSession.php',
            'EmployeeMFABackupCode.php',
        ];
        
        $updated = 0;
        $skipped = 0;
        
        foreach ($files as $file) {
            $filename = $file->getFilename();
            
            // Skip excluded models
            if (in_array($filename, $excludeModels)) {
                $this->info("Skipped: {$filename}");
                $skipped++;
                continue;
            }
            
            $content = File::get($file->getPathname());
            
            // Check if already has the trait
            if (strpos($content, 'use AutoLogsActivity') !== false) {
                $this->info("Already has trait: {$filename}");
                $skipped++;
                continue;
            }
            
            // Add use statement
            if (strpos($content, 'use App\Traits\AutoLogsActivity;') === false) {
                $content = preg_replace(
                    '/(namespace\s+App\\\\Models;.*?\n)(use\s+)/s',
                    "$1use App\Traits\AutoLogsActivity;\n$2",
                    $content
                );
            }
            
            // Add trait to class
            $content = preg_replace(
                '/(class\s+\w+\s+extends\s+Model\s*\{[\s\n]+)(use\s+[^;]+;)/s',
                "$1$2, AutoLogsActivity",
                $content
            );
            
            // If no existing traits, add it
            if (!preg_match('/(use\s+[^;]+;)/s', $content)) {
                $content = preg_replace(
                    '/(class\s+\w+\s+extends\s+Model\s*\{[\s\n]+)/s',
                    "$1    use AutoLogsActivity;\n\n",
                    $content
                );
            }
            
            File::put($file->getPathname(), $content);
            $this->info("Updated: {$filename}");
            $updated++;
        }
        
        $this->newLine();
        $this->info("✅ Activity logging added to {$updated} models");
        $this->info("⏭️  Skipped {$skipped} models");
        
        return Command::SUCCESS;
    }
}
