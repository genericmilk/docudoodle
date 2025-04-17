<?php

namespace Docudoodle\Commands;

use Illuminate\Console\Command;
use Docudoodle\Docudoodle;

class GenerateDocsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'docudoodle:generate 
                            {--source=* : Directories to process (default: from config or app/, config/, routes/, database/)}
                            {--output= : Output directory for documentation (default: from config or "documentation")}
                            {--model= : Model to use (default: from config or gpt-4o-mini)}
                            {--max-tokens= : Maximum tokens for API calls (default: from config or 10000)}
                            {--extensions=* : File extensions to process (default: from config or php, yaml, yml)}
                            {--skip=* : Subdirectories to skip (default: from config or vendor/, node_modules/, tests/, cache/)}
                            {--api-provider= : API provider to use (default: from config or openai)}
                            {--azure-endpoint= : Azure OpenAI endpoint (default: from config)}
                            {--azure-deployment= : Azure OpenAI deployment ID (default: from config)}
                            {--azure-api-version= : Azure OpenAI API version (default: from config or 2023-05-15)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate documentation for your Laravel application';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $apiProvider = $this->option('api-provider') ?: config('docudoodle.default_api_provider', 'openai');
        $apiKey = '';
        
        if ($apiProvider === 'openai') {
            $apiKey = config('docudoodle.openai_api_key');
            if (empty($apiKey)) {
                $this->error('Oops! OpenAI API key is not set in the configuration!');
                return 1;
            }
        } elseif ($apiProvider === 'claude') {
            $apiKey = config('docudoodle.claude_api_key');
            if (empty($apiKey)) {
                $this->error('Oops! Claude API key is not set in the configuration!');
                return 1;
            }
        } elseif ($apiProvider === 'gemini') {
            $apiKey = config('docudoodle.gemini_api_key');
            if (empty($apiKey)) {
                $this->error('Oops! Gemini API key is not set in the configuration!');
                return 1;
            }
        } elseif ($apiProvider === 'azure') {
            $apiKey = config('docudoodle.azure_api_key');
            if (empty($apiKey)) {
                $this->error('Oops! Azure OpenAI API key is not set in the configuration!');
                return 1;
            }
        }
        
        // Parse command options with config fallbacks
        $sourceDirs = $this->option('source');
        if (empty($sourceDirs)) {
            $sourceDirs = config('docudoodle.source_dirs', ['app/', 'config/', 'routes/', 'database/']);
        }
        
        $outputDir = $this->option('output');
        if (empty($outputDir)) {
            $outputDir = config('docudoodle.output_dir', 'documentation');
        }
        
        $model = $this->option('model');
        if (empty($model)) {
            $model = config('docudoodle.default_model', 'gpt-4o-mini');
        }
        
        $maxTokens = $this->option('max-tokens');
        if (empty($maxTokens)) {
            $maxTokens = (int) config('docudoodle.max_tokens', 10000);
        } else {
            $maxTokens = (int) $maxTokens;
        }
        
        $extensions = $this->option('extensions');
        if (empty($extensions)) {
            $extensions = config('docudoodle.extensions', ['php', 'yaml', 'yml']);
        }
        
        $skipSubdirs = $this->option('skip');
        if (empty($skipSubdirs)) {
            $skipSubdirs = config('docudoodle.skip_subdirs', ['vendor/', 'node_modules/', 'tests/', 'cache/']);
        }

        $ollamaHost = config('docudoodle.ollama_host', 'localhost');
        $ollamaPort = config('docudoodle.ollama_port', 5000);
        
        // Azure OpenAI specific configuration
        $azureEndpoint = $this->option('azure-endpoint');
        $azureDeployment = $this->option('azure-deployment');
        $azureApiVersion = $this->option('azure-api-version');
        
        if (empty($azureEndpoint)) {
            $azureEndpoint = config('docudoodle.azure_endpoint', '');
        }
        if (empty($azureDeployment)) {
            $azureDeployment = config('docudoodle.azure_deployment', '');
        }
        if (empty($azureApiVersion)) {
            $azureApiVersion = config('docudoodle.azure_api_version', '2023-05-15');
        }
        
        // Convert relative paths to absolute paths based on Laravel's base path
        $sourceDirs = array_map(function($dir) {
            return base_path($dir);
        }, $sourceDirs);
        
        $outputDir = base_path($outputDir);
        
        $this->info('Starting documentation generation...');
        $this->info('Source directories: ' . implode(', ', $sourceDirs));
        $this->info('Output directory: ' . $outputDir);
        $this->info('API provider: ' . $apiProvider);
        
        try {
            $generator = new Docudoodle(
                $apiKey,
                $sourceDirs,
                $outputDir,
                $model,
                $maxTokens,
                $extensions,
                $skipSubdirs,
                $apiProvider,
                $ollamaHost,
                $ollamaPort,
                __DIR__.'/../../resources/templates/default-prompt.md',
                $azureEndpoint,
                $azureDeployment,
                $azureApiVersion
            );
            
            $generator->generate();
            
            $this->info('Documentation generated successfully!');
            return 0;
        } catch (\Exception $e) {
            $this->error('Error generating documentation: ' . $e->getMessage());
            return 1;
        }
    }
}