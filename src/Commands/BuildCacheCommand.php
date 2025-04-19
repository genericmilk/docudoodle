<?php

namespace Docudoodle\Commands;

use Illuminate\Console\Command;
use Docudoodle\Docudoodle;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Exception;

class BuildCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'docudoodle:build-cache
                            {--source=* : Source directories to scan (default: from config)}
                            {--output= : Documentation output directory (default: from config)}
                            {--cache-path= : Path to the cache file (overrides config)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Builds the Docudoodle cache file based on existing source and documentation files.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting cache build process...');

        // --- Configuration Loading (Similar to GenerateDocsCommand) ---
        // Get source directories
        $sourceDirs = $this->option('source');
        if (empty($sourceDirs)) {
            $sourceDirs = config('docudoodle.source_dirs', ['app/', 'config/', 'routes/', 'database/']);
        }
        $sourceDirs = array_map(fn($dir) => base_path($dir), $sourceDirs);

        // Get output directory
        $outputDir = $this->option('output') ?: config('docudoodle.output_dir', 'documentation');
        $outputDir = base_path($outputDir);

        // Determine cache file path
        $cachePath = $this->option('cache-path') ?: config('docudoodle.cache_file_path', null);
        if (empty($cachePath)) {
             $cachePath = rtrim($outputDir, '/') . '/.docudoodle_cache.json';
        }

        // Get other config needed for hash calculation
        $model = config('docudoodle.default_model', 'gpt-4o-mini');
        $apiProvider = config('docudoodle.default_api_provider', 'openai');
        // Assuming prompt template is configurable
        $promptTemplatePath = config('docudoodle.prompt_template', __DIR__ . '/../../resources/templates/default-prompt.md'); 
        $extensions = config('docudoodle.extensions', ['php', 'yaml', 'yml']);
        $skipSubdirs = config('docudoodle.skip_subdirs', ['vendor/', 'node_modules/', 'tests/', 'cache/']);

        $this->info('Source directories: ' . implode(', ', $sourceDirs));
        $this->info('Output directory: ' . $outputDir);
        $this->info("Target cache file: {$cachePath}");

        // --- Build Cache Logic --- 
        try {
            // Instantiate Docudoodle mainly for helper methods (config hash, maybe path logic)
            // We pass dummy/minimal values for things we don't need directly (like API key)
            // We also explicitly disable the cache/force rebuild *within* this instance 
            // to prevent it from trying to load/use the cache we are building.
            $docudoodleHelper = new Docudoodle(
                openaiApiKey: '', // Not needed for cache build
                sourceDirs: $sourceDirs,
                outputDir: $outputDir,
                model: $model,
                maxTokens: 1, // Minimal
                allowedExtensions: $extensions,
                skipSubdirectories: $skipSubdirs,
                apiProvider: $apiProvider,
                ollamaHost: '', // Not needed
                ollamaPort: 0,  // Not needed
                promptTemplate: $promptTemplatePath,
                useCache: false, // Important: Don't use cache features of this instance
                cacheFilePath: null, // Not relevant for this instance
                forceRebuild: true // Ensure it doesn't try to use its own (non-existent) cache state
            );

            // Calculate current config hash using helper method
            // Need to make calculateConfigHash public/protected or duplicate logic
            // For now, assume we duplicate/adapt the logic here
            $configHash = $this->calculateConfigHash($model, $apiProvider, $promptTemplatePath);
            $this->info("Calculated configuration hash: {$configHash}");

            $hashMap = ['_config_hash' => $configHash];
            $foundCount = 0;
            $addedCount = 0;

            $this->info('Scanning source files and checking for existing documentation...');
            
            foreach ($sourceDirs as $sourceDir) {
                if (!is_dir($sourceDir)) {
                    $this->warn("Source directory not found: {$sourceDir}");
                    continue;
                }
                
                $baseDir = rtrim($sourceDir, "/");
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $baseDir,
                        RecursiveDirectoryIterator::SKIP_DOTS
                    )
                );
                
                foreach ($iterator as $file) {
                    if ($file->isDir()) continue;

                    $sourcePath = $file->getPathname();
                    $foundCount++;
                    
                    // Rough check if file should be processed (adapt shouldProcessFile logic)
                    $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
                    if (!in_array($ext, $extensions) || strpos(basename($sourcePath), '.') === 0) {
                        continue;
                    }
                    
                    // Rough check if directory should be processed (adapt shouldProcessDirectory logic)
                    $relFilePathCheck = substr($sourcePath, strlen($baseDir) + 1);
                    $relDirPathCheck = dirname($relFilePathCheck);
                    if (!$this->shouldProcessDirectoryCheck($relDirPathCheck, $skipSubdirs)) {
                        continue;
                    }

                    // Calculate expected doc path (adapt createDocumentationFile logic)
                    $docPath = $this->calculateDocumentationPath($sourcePath, $baseDir, $outputDir);
                    
                    if (file_exists($docPath)) {
                        $fileHash = sha1_file($sourcePath);
                        if ($fileHash !== false) {
                            $hashMap[$sourcePath] = $fileHash;
                            $addedCount++;
                        }
                    } else {
                         //$this->line(" - Doc not found for: {$sourcePath}"); // Optional verbosity
                    }
                }
            }
            
            $this->info("Scan complete. Found {$foundCount} total files, added {$addedCount} entries to cache.");

            // Save the hash map (adapt saveHashMap logic)
            $this->saveHashMap($hashMap, $cachePath);
            $this->info("Cache file built successfully at: {$cachePath}");
            
            return 0;

        } catch (Exception $e) {
            $this->error("Error building cache file: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Duplicated/adapted logic to calculate config hash.
     */
    private function calculateConfigHash(string $model, string $apiProvider, string $promptTemplatePath): string
    {
        $realTemplatePath = realpath($promptTemplatePath) ?: $promptTemplatePath; // Use realpath or fallback
        $configData = [
            'model' => $model,
            'apiProvider' => $apiProvider,
            'promptTemplatePath' => $realTemplatePath, // Use normalized path
            'promptTemplateContent' => file_exists($promptTemplatePath) ? sha1_file($promptTemplatePath) : 'template_not_found'
        ];
        return sha1(json_encode($configData));
    }

    /**
     * Duplicated/adapted logic to save hash map.
     */
    private function saveHashMap(array $map, string $cachePath): void
    {
        try {
            $dir = dirname($cachePath);
            if (!is_dir($dir)) {
                 mkdir($dir, 0755, true);
            }
            $content = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($content === false) {
                throw new Exception("Failed to encode hash map to JSON.");
            }
            file_put_contents($cachePath, $content);
        } catch (Exception $e) {
            throw new Exception("Could not save cache file: {$cachePath} - " . $e->getMessage());
        }
    }

    /**
     * Duplicated/adapted logic to calculate expected doc path.
     */
    private function calculateDocumentationPath(string $sourcePath, string $baseSourceDir, string $outputDir): string
    {
        $relPath = substr($sourcePath, strlen(rtrim($baseSourceDir, '/')) + 1);
        $sourceDirName = basename(rtrim($baseSourceDir, "/"));
        $fullRelPath = $sourceDirName . "/" . $relPath;
        $relDir = dirname($fullRelPath);
        $fileName = pathinfo($relPath, PATHINFO_FILENAME);
        return rtrim($outputDir, "/") . "/" . $relDir . "/" . $fileName . ".md";
    }
    
    /**
     * Duplicated/adapted logic for shouldProcessDirectory.
     */
    private function shouldProcessDirectoryCheck(string $relDirPath, array $skipSubdirs): bool
    {
        // Simplified check - assumes relative path from source dir root
        $dirPath = rtrim($relDirPath, "/") . "/";
        if ($dirPath === './' || $dirPath === '/') return true; // Allow root
        
        foreach ($skipSubdirs as $skipDir) {
            $skipDir = rtrim($skipDir, "/") . "/";
            if (strpos($dirPath, $skipDir) === 0) {
                return false;
            }
            $pathParts = explode("/", trim($dirPath, "/"));
            foreach ($pathParts as $part) {
                if (!empty($part) && $part . "/" === $skipDir) {
                    return false;
                }
            }
        }
        return true;
    }
} 