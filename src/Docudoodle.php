<?php

namespace Docudoodle;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionFunction;

/**
 * PHP Documentation Generator
 *
 * This class generates documentation for a PHP codebase by analyzing source files
 * and using the OpenAI API to create comprehensive documentation.
 */
class Docudoodle
{
    /**
     * Constructor for Docudoodle
     *
     * @param string $apiKey OpenAI/Claude/Gemini API key (not needed for Ollama)
     * @param array $sourceDirs Directories to process
     * @param string $outputDir Directory for generated documentation
     * @param string $model AI model to use
     * @param int $maxTokens Maximum tokens for API calls
     * @param array $allowedExtensions File extensions to process
     * @param array $skipSubdirectories Subdirectories to skip
     * @param string $apiProvider API provider to use (default: 'openai')
     * @param string $ollamaHost Ollama host (default: 'localhost')
     * @param int $ollamaPort Ollama port (default: 5000)
     * @param string $promptTemplate Path to prompt template markdown file
     * @param string $azureEndpoint Azure OpenAI endpoint URL (default: "")
     * @param string $azureDeployment Azure OpenAI deployment ID (default: "")
     * @param string $azureApiVersion Azure OpenAI API version (default: "2023-05-15")
     */
    public function __construct(
        private string $openaiApiKey = "",
        private array $sourceDirs = ["app/", "config/", "routes/", "database/"],
        private string $outputDir = "documentation/",
        private string $model = "gpt-4o-mini",
        private int $maxTokens = 10000,
        private array $allowedExtensions = ["php", "yaml", "yml"],
        private array $skipSubdirectories = [
            "vendor/",
            "node_modules/",
            "tests/",
            "cache/",
        ],
        private string $apiProvider = "openai",
        private string $ollamaHost = "localhost",
        private int $ollamaPort = 5000,
        private string $promptTemplate = __DIR__ . "/../resources/templates/default-prompt.md",
        private string $azureEndpoint = "",
        private string $azureDeployment = "",
        private string $azureApiVersion = "2023-05-15"
    ) {
    }

    /**
     * Application context data collected during processing
     */
    private array $appContext = [
        'routes' => [],
        'controllers' => [],
        'models' => [],
        'relationships' => [],
        'imports' => [],
    ];

    /**
     * Ensure the output directory exists
     */
    private function ensureDirectoryExists($directoryPath): void
    {
        if (!file_exists($directoryPath)) {
            mkdir($directoryPath, 0755, true);
        }
    }

    /**
     * Get the file extension
     */
    private function getFileExtension($filePath): string
    {
        return pathinfo($filePath, PATHINFO_EXTENSION);
    }

    /**
     * Determine if file should be processed based on extension
     */
    private function shouldProcessFile($filePath): bool
    {
        $ext = strtolower($this->getFileExtension($filePath));
        $baseName = basename($filePath);

        // Skip hidden files
        if (strpos($baseName, ".") === 0) {
            return false;
        }

        // Only process files with allowed extensions
        return in_array($ext, $this->allowedExtensions);
    }

    /**
     * Check if directory should be processed based on allowed subdirectories
     */
    private function shouldProcessDirectory($dirPath): bool
    {
        // Normalize directory path for comparison
        $dirPath = rtrim($dirPath, "/") . "/";

        // Check if directory or any parent directory is in the skip list
        foreach ($this->skipSubdirectories as $skipDir) {
            $skipDir = rtrim($skipDir, "/") . "/";

            // Check if this directory is a subdirectory of a skipped directory
            // or if it matches exactly a skipped directory
            if (strpos($dirPath, $skipDir) === 0 || $dirPath === $skipDir) {
                return false;
            }

            // Also check if any segment of the path matches a skipped directory
            $pathParts = explode("/", trim($dirPath, "/"));
            foreach ($pathParts as $part) {
                if ($part . "/" === $skipDir) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Read the content of a file safely
     */
    private function readFileContent($filePath): string
    {
        try {
            return file_get_contents($filePath);
        } catch (Exception $e) {
            return "Error reading file: " . $e->getMessage();
        }
    }

    /**
     * Remove <think></think> tags from the response
     */
    private function cleanResponse(string $response): string
    {
        return preg_replace('/<think>.*?<\/think>/', '', $response);
    }

    /**
     * Generate documentation using the selected API provider
     */
    private function generateDocumentation($filePath, $content): string
    {
        // Collect context about this file and its relationships before generating documentation
        $fileContext = $this->collectFileContext($filePath, $content);
        
        if ($this->apiProvider === "ollama") {
            return $this->generateDocumentationWithOllama($filePath, $content, $fileContext);
        } elseif ($this->apiProvider === "claude") {
            return $this->generateDocumentationWithClaude($filePath, $content, $fileContext);
        } elseif ($this->apiProvider === "gemini") {
            return $this->generateDocumentationWithGemini($filePath, $content, $fileContext);
        } elseif ($this->apiProvider === "azure") {
            return $this->generateDocumentationWithAzureOpenAI($filePath, $content, $fileContext);
        } else {
            return $this->generateDocumentationWithOpenAI($filePath, $content, $fileContext);
        }
    }

    /**
     * Collect context information about a file and its relationships
     * 
     * @param string $filePath Path to the file
     * @param string $content Content of the file
     * @return array Context information
     */
    private function collectFileContext(string $filePath, string $content): array
    {
        $fileExt = pathinfo($filePath, PATHINFO_EXTENSION);
        $context = [
            'imports' => [],
            'relatedFiles' => [],
            'routes' => [],
            'controllers' => [],
            'models' => [],
        ];
        
        // Extract namespace and class name
        $namespace = $this->extractNamespace($content);
        $className = $this->extractClassName($content);
        $fullClassName = $namespace ? "$namespace\\$className" : $className;
        
        // Extract imports/use statements
        $imports = $this->extractImports($content);
        $context['imports'] = $imports;
        
        // Different analysis based on file type
        if ($fileExt === 'php') {
            // Check if this is a controller
            if (strpos($filePath, 'Controller') !== false || 
                strpos($className, 'Controller') !== false) {
                $context['isController'] = true;
                $context['controllerActions'] = $this->extractControllerActions($content);
                $this->appContext['controllers'][$fullClassName] = [
                    'path' => $filePath,
                    'actions' => $context['controllerActions']
                ];
            }
            
            // Check if this is a model
            if (strpos($filePath, 'Model') !== false || 
                $this->isLikelyModel($content)) {
                $context['isModel'] = true;
                $context['modelRelationships'] = $this->extractModelRelationships($content);
                $this->appContext['models'][$fullClassName] = [
                    'path' => $filePath,
                    'relationships' => $context['modelRelationships']
                ];
            }
            
            // Find related route definitions
            $context['routes'] = $this->findRelatedRoutes($className, $fullClassName);
        } 
        
        // Check if it's a route file
        else if ($fileExt === 'php' && (strpos($filePath, 'routes') !== false || 
                 strpos($filePath, 'web.php') !== false || 
                 strpos($filePath, 'api.php') !== false)) {
            $context['isRouteFile'] = true;
            $routeData = $this->extractRoutes($content);
            $context['definedRoutes'] = $routeData;
            $this->appContext['routes'] = array_merge($this->appContext['routes'], $routeData);
        }
        
        // For all files, find related files based on imports
        foreach ($imports as $import) {
            // Convert import to possible file path
            $potentialFile = $this->findFileFromImport($import);
            if ($potentialFile) {
                $context['relatedFiles'][$import] = $potentialFile;
            }
        }
        
        return $context;
    }
    
    /**
     * Extract namespace from PHP content
     */
    private function extractNamespace(string $content): string
    {
        if (preg_match('/namespace\s+([^;]+);/i', $content, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }
    
    /**
     * Extract class name from PHP content
     */
    private function extractClassName(string $content): string
    {
        if (preg_match('/class\s+(\w+)(?:\s+extends|\s+implements|\s*\{)/i', $content, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }
    
    /**
     * Extract import/use statements from PHP content
     */
    private function extractImports(string $content): array
    {
        $imports = [];
        if (preg_match_all('/use\s+([^;]+);/i', $content, $matches)) {
            foreach ($matches[1] as $import) {
                $imports[] = trim($import);
            }
        }
        return $imports;
    }
    
    /**
     * Extract controller action methods
     */
    private function extractControllerActions(string $content): array
    {
        $actions = [];
        
        // Look for public methods that might be controller actions
        if (preg_match_all('/public\s+function\s+(\w+)\s*\([^)]*\)/i', $content, $matches)) {
            foreach ($matches[1] as $method) {
                // Skip common non-action methods
                if (in_array($method, ['__construct', '__destruct', 'middleware'])) {
                    continue;
                }
                $actions[] = $method;
            }
        }
        
        return $actions;
    }
    
    /**
     * Check if a PHP file is likely a model
     */
    private function isLikelyModel(string $content): bool
    {
        // Check for common model indicators
        $modelPatterns = [
            '/extends\s+Model/i',
            '/class\s+\w+\s+extends\s+\w*Model\b/i',
            '/use\s+Illuminate\\\\Database\\\\Eloquent\\\\Model/i',
            '/\$table\s*=/i',
            '/\$fillable\s*=/i',
            '/\$guarded\s*=/i',
            '/hasMany|hasOne|belongsTo|belongsToMany/i'
        ];
        
        foreach ($modelPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract model relationships from content
     */
    private function extractModelRelationships(string $content): array
    {
        $relationships = [];
        
        $relationshipTypes = ['hasMany', 'hasOne', 'belongsTo', 'belongsToMany', 
                             'hasOneThrough', 'hasManyThrough', 'morphTo', 
                             'morphOne', 'morphMany', 'morphToMany'];
                             
        foreach ($relationshipTypes as $type) {
            if (preg_match_all('/function\s+(\w+)\s*\([^)]*\)[^{]*{[^}]*\$this->' . $type . '\s*\(\s*([^,\)]+)/i', 
                $content, $matches, PREG_SET_ORDER)) {
                
                foreach ($matches as $match) {
                    $methodName = trim($match[1]);
                    $relatedModel = trim($match[2], "'\" \t\n\r\0\x0B");
                    
                    $relationships[] = [
                        'method' => $methodName,
                        'type' => $type,
                        'related' => $relatedModel
                    ];
                }
            }
        }
        
        return $relationships;
    }
    
    /**
     * Extract routes from a routes file
     */
    private function extractRoutes(string $content): array
    {
        $routes = [];
        
        // Match route definitions like Route::get('/path', 'Controller@method')
        $routePatterns = [
            // Route::get('/path', 'Controller@method')
            '/Route::(get|post|put|patch|delete|options|any)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^@\'"]*)@([^\'"]*)[\'"]/',
            
            // Route::get('/path', [Controller::class, 'method'])
            '/Route::(get|post|put|patch|delete|options|any)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\[\s*([^:,]+)::class\s*,\s*[\'"]([^\'"]+)[\'"]/',
            
            // Route names: ->name('route.name')
            '/->name\s*\(\s*[\'"]([^\'"]+)[\'"]/'
        ];
        
        $currentRoute = null;
        
        // Split content by lines to process one at a time
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            // Check for HTTP method and path
            if (preg_match($routePatterns[0], $line, $matches)) {
                $currentRoute = [
                    'method' => strtoupper($matches[1]),
                    'path' => $matches[2],
                    'controller' => $matches[3],
                    'action' => $matches[4],
                ];
                $routes[] = $currentRoute;
            }
            // Check for array style controller
            else if (preg_match($routePatterns[1], $line, $matches)) {
                $currentRoute = [
                    'method' => strtoupper($matches[1]),
                    'path' => $matches[2],
                    'controller' => $matches[3],
                    'action' => $matches[4],
                ];
                $routes[] = $currentRoute;
            }
            // Check for route name
            else if (preg_match($routePatterns[2], $line, $matches) && $currentRoute) {
                $lastIndex = count($routes) - 1;
                if ($lastIndex >= 0) {
                    $routes[$lastIndex]['name'] = $matches[1];
                }
            }
        }
        
        return $routes;
    }
    
    /**
     * Find routes related to a controller
     */
    private function findRelatedRoutes(string $className, string $fullClassName): array
    {
        $relatedRoutes = [];
        
        foreach ($this->appContext['routes'] as $route) {
            if (isset($route['controller'])) {
                // Check against both short and full class names
                if ($route['controller'] === $className || 
                    $route['controller'] === $fullClassName) {
                    $relatedRoutes[] = $route;
                }
            }
        }
        
        return $relatedRoutes;
    }
    
    /**
     * Try to find a file based on an import statement
     */
    private function findFileFromImport(string $import): string
    {
        // Convert namespace to path (App\Http\Controllers\UserController -> app/Http/Controllers/UserController.php)
        $potentialPath = str_replace('\\', '/', $import) . '.php';
        
        // Try common base directories
        $baseDirs = $this->sourceDirs;
        
        foreach ($baseDirs as $baseDir) {
            $fullPath = $baseDir . '/' . $potentialPath;
            if (file_exists($fullPath)) {
                return $fullPath;
            }
            
            // Try with lowercase first directory
            $parts = explode('/', $potentialPath);
            if (count($parts) > 0) {
                $parts[0] = strtolower($parts[0]);
                $altPath = implode('/', $parts);
                $fullPath = $baseDir . '/' . $altPath;
                if (file_exists($fullPath)) {
                    return $fullPath;
                }
            }
        }
        
        return '';
    }

    /**
     * Load and process prompt template with variables and context
     * 
     * @param string $filePath Path to the file being documented
     * @param string $content Content of the file being documented
     * @param array $context Additional context information about the file
     * @return string Processed prompt with variables replaced
     */
    private function loadPromptTemplate(string $filePath, string $content, array $context = []): string
    {
        try {
            // Default to built-in template if custom template doesn't exist
            $templatePath = $this->promptTemplate;
            if (!file_exists($templatePath)) {
                $templatePath = __DIR__ . "/../resources/templates/default-prompt.md";
            }
            
            if (!file_exists($templatePath)) {
                throw new Exception("Prompt template not found: {$templatePath}");
            }
            
            $template = file_get_contents($templatePath);
            
            // Format the context information as markdown
            $contextMd = $this->formatContextAsMarkdown($context);
            
            // Replace variables in the template
            $variables = [
                '{FILE_PATH}' => $filePath,
                '{FILE_CONTENT}' => $content,
                '{FILE_NAME}' => basename($filePath),
                '{EXTENSION}' => pathinfo($filePath, PATHINFO_EXTENSION),
                '{BASE_NAME}' => pathinfo($filePath, PATHINFO_FILENAME),
                '{DIRECTORY}' => dirname($filePath),
                '{CONTEXT}' => $contextMd,
                '{TOC_LINK}' => $this->normalizeForToc(basename($filePath)), // Add normalized TOC link
            ];
            
            return str_replace(array_keys($variables), array_values($variables), $template);
        } catch (Exception $e) {
            // If template loading fails, return a basic default prompt
            return "Please document the PHP file {$filePath}. Here's the content:\n\n```\n{$content}\n```";
        }
    }

    /**
     * Format context information as markdown
     * 
     * @param array $context Context information
     * @return string Formatted context as markdown
     */
    private function formatContextAsMarkdown(array $context): string
    {
        $md = "";
        
        if (!empty($context['imports'])) {
            $md .= "### Imports\n";
            foreach ($context['imports'] as $import) {
                $md .= "- $import\n";
            }
            $md .= "\n";
        }
        
        if (!empty($context['relatedFiles'])) {
            $md .= "### Related Files\n";
            foreach ($context['relatedFiles'] as $import => $file) {
                $md .= "- $import: $file\n";
            }
            $md .= "\n";
        }
        
        if (!empty($context['routes'])) {
            $md .= "### Related Routes\n";
            foreach ($context['routes'] as $route) {
                $md .= "- {$route['method']} {$route['path']} -> {$route['controller']}@{$route['action']}\n";
            }
            $md .= "\n";
        }
        
        if (!empty($context['controllerActions'])) {
            $md .= "### Controller Actions\n";
            foreach ($context['controllerActions'] as $action) {
                $md .= "- $action\n";
            }
            $md .= "\n";
        }
        
        if (!empty($context['modelRelationships'])) {
            $md .= "### Model Relationships\n";
            foreach ($context['modelRelationships'] as $relationship) {
                $md .= "- {$relationship['method']} ({$relationship['type']}) -> {$relationship['related']}\n";
            }
            $md .= "\n";
        }
        
        return $md;
    }

    /**
     * Generate documentation using OpenAI API
     */
    private function generateDocumentationWithOpenAI($filePath, $content, $context = []): string
    {
        try {
            // Check content length and truncate if necessary
            if (strlen($content) > $this->maxTokens * 4) {
                // Rough estimate of token count
                $content =
                    substr($content, 0, $this->maxTokens * 4) .
                    "\n...(truncated for length)...";
            }

            $prompt = $this->loadPromptTemplate($filePath, $content, $context);

            $postData = [
                "model" => $this->model,
                "messages" => [
                    [
                        "role" => "system",
                        "content" =>
                            "You are a technical documentation specialist with expertise in PHP applications.",
                    ],
                    ["role" => "user", "content" => $prompt],
                ],
                "max_tokens" => 1500,
            ];

            $ch = curl_init("https://api.openai.com/v1/chat/completions");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Authorization: Bearer " . $this->openaiApiKey,
            ]);

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception(curl_error($ch));
            }
            curl_close($ch);

            $responseData = json_decode($response, true);

            if (isset($responseData["choices"][0]["message"]["content"])) {
                return $responseData["choices"][0]["message"]["content"];
            } else {
                throw new Exception("Unexpected API response format");
            }
        } catch (Exception $e) {
            return "# Documentation Generation Error\n\nThere was an error generating documentation for this file: " .
                $e->getMessage();
        }
    }

    /**
     * Generate documentation using Azure OpenAI API
     */
    private function generateDocumentationWithAzureOpenAI($filePath, $content, $context = []): string
    {
        try {
            // Check content length and truncate if necessary
            if (strlen($content) > $this->maxTokens * 4) {
                // Rough estimate of token count
                $content =
                    substr($content, 0, $this->maxTokens * 4) .
                    "\n...(truncated for length)...";
            }

            $prompt = $this->loadPromptTemplate($filePath, $content, $context);

            $postData = [
                "messages" => [
                    [
                        "role" => "system",
                        "content" => "You are a technical documentation specialist with expertise in PHP applications.",
                    ],
                    ["role" => "user", "content" => $prompt],
                ],
                "max_tokens" => 1500,
            ];

            // Azure OpenAI API requires a different endpoint format and authentication method
            $endpoint = rtrim($this->azureEndpoint, '/');
            $url = "{$endpoint}/openai/deployments/{$this->azureDeployment}/chat/completions?api-version={$this->azureApiVersion}";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "api-key: " . $this->openaiApiKey,
            ]);

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception(curl_error($ch));
            }
            curl_close($ch);

            $responseData = json_decode($response, true);

            if (isset($responseData["choices"][0]["message"]["content"])) {
                return $responseData["choices"][0]["message"]["content"];
            } else {
                throw new Exception("Unexpected Azure OpenAI API response format: " . json_encode($responseData));
            }
        } catch (Exception $e) {
            return "# Documentation Generation Error\n\nThere was an error generating documentation for this file: " .
                $e->getMessage();
        }
    }

    /**
     * Generate documentation using Ollama API
     */
    private function generateDocumentationWithOllama($filePath, $content, $context = []): string
    {
        try {
            // Check content length and truncate if necessary
            if (strlen($content) > $this->maxTokens * 4) {
                // Rough estimate of token count
                $content =
                    substr($content, 0, $this->maxTokens * 4) .
                    "\n...(truncated for length)...";
            }

            $prompt = $this->loadPromptTemplate($filePath, $content, $context);

            $postData = [
                "model" => $this->model,
                "messages" => [
                    [
                        "role" => "system",
                        "content" =>
                            "You are a technical documentation specialist with expertise in PHP applications.",
                    ],
                    ["role" => "user", "content" => $prompt],
                ],
                "max_tokens" => $this->maxTokens,
                "stream" => false,
            ];

            // Ollama runs locally on the configured host and port
            $ch = curl_init(
                "http://{$this->ollamaHost}:{$this->ollamaPort}/api/chat"
            );
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
            ]);

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception(curl_error($ch));
            }
            curl_close($ch);

            $responseData = json_decode($response, true);

            if (isset($responseData["message"]["content"])) {
                return $responseData["message"]["content"];
            } else {
                throw new Exception("Unexpected API response format");
            }
        } catch (Exception $e) {
            return "# Documentation Generation Error\n\nThere was an error generating documentation for this file: " .
                $e->getMessage();
        }
    }

    /**
     * Generate documentation using Claude API
     */
    private function generateDocumentationWithClaude($filePath, $content, $context = []): string
    {
        try {
            // Check content length and truncate if necessary
            if (strlen($content) > $this->maxTokens * 4) {
                // Rough estimate of token count
                $content =
                    substr($content, 0, $this->maxTokens * 4) .
                    "\n...(truncated for length)...";
            }

            $prompt = $this->loadPromptTemplate($filePath, $content, $context);

            $postData = [
                "model" => $this->model,
                "messages" => [
                    [
                        "role" => "system",
                        "content" =>
                            "You are a technical documentation specialist with expertise in PHP applications.",
                    ],
                    ["role" => "user", "content" => $prompt],
                ],
                "max_tokens" => $this->maxTokens,
                "stream" => false,
            ];

            // Claude API endpoint
            $ch = curl_init("https://api.claude.ai/v1/chat/completions");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Authorization: Bearer " . $this->openaiApiKey,
            ]);

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception(curl_error($ch));
            }
            curl_close($ch);

            $responseData = json_decode($response, true);

            if (isset($responseData["choices"][0]["message"]["content"])) {
                return $responseData["choices"][0]["message"]["content"];
            } else {
                throw new Exception("Unexpected API response format");
            }
        } catch (Exception $e) {
            return "# Documentation Generation Error\n\nThere was an error generating documentation for this file: " .
                $e->getMessage();
        }
    }

    /**
     * Generate documentation using Gemini API
     */
    private function generateDocumentationWithGemini($filePath, $content, $context = []): string
    {
        try {
            // Check content length and truncate if necessary
            if (strlen($content) > $this->maxTokens * 4) {
                // Rough estimate of token count
                $content =
                    substr($content, 0, $this->maxTokens * 4) .
                    "\n...(truncated for length)...";
            }

            $prompt = $this->loadPromptTemplate($filePath, $content, $context);

            $postData = [
                "contents" => [
                    [
                        "role" => "user",
                        "parts" => [
                            ["text" => $prompt]
                        ]
                    ]
                ],
                "generationConfig" => [
                    "maxOutputTokens" => $this->maxTokens,
                    "temperature" => 0.2,
                    "topP" => 0.9
                ]
            ];

            // Determine which Gemini model to use (gemini-1.5-pro by default if not specified)
            $geminiModel = ($this->model === "gemini" || $this->model === "gemini-pro") ? "gemini-1.5-pro" : $this->model;
            
            $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$geminiModel}:generateContent?key={$this->openaiApiKey}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json"
            ]);

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception(curl_error($ch));
            }
            curl_close($ch);

            $responseData = json_decode($response, true);

            if (isset($responseData["candidates"][0]["content"]["parts"][0]["text"])) {
                return $responseData["candidates"][0]["content"]["parts"][0]["text"];
            } else {
                throw new Exception("Unexpected Gemini API response format: " . json_encode($responseData));
            }
        } catch (Exception $e) {
            return "# Documentation Generation Error\n\nThere was an error generating documentation for this file: " .
                $e->getMessage();
        }
    }

    /**
     * Create documentation file for a given source file
     */
    private function createDocumentationFile($sourcePath, $relPath, $sourceDir): void
    {
        // Define output path - preserve complete directory structure including source directory name
        $outputDir = rtrim($this->outputDir, "/") . "/";

        // Get just the source directory basename (without full path)
        $sourceDirName = basename(rtrim($sourceDir, "/"));

        // Prepend the source directory name to the relative path to maintain the full structure
        $fullRelPath = $sourceDirName . "/" . $relPath;
        $relDir = dirname($fullRelPath);
        $fileName = pathinfo($relPath, PATHINFO_FILENAME);

        // Create proper output path
        $outputPath = $outputDir . $relDir . "/" . $fileName . ".md";

        // Skip if documentation file already exists
        if (file_exists($outputPath)) {
            echo "Documentation already exists: {$outputPath} - skipping\n";
            return;
        }

        // Ensure the directory exists
        $this->ensureDirectoryExists(dirname($outputPath));

        // Check if file is valid for processing
        if (!$this->shouldProcessFile($sourcePath)) {
            return;
        }

        // Read content
        $content = $this->readFileContent($sourcePath);

        // Generate documentation
        echo "Generating documentation for {$sourcePath}...\n";
        $docContent = $this->generateDocumentation($sourcePath, $content);

        // Clean the documentation response
        $docContent = $this->cleanResponse($docContent);

        // Write to file
        $fileContent = "# Documentation: " . basename($sourcePath) . "\n\n";
        $fileContent .= "Original file: `{$fullRelPath}`\n\n"; // Use full relative path here
        $fileContent .= $docContent;

        file_put_contents($outputPath, $fileContent);

        echo "Documentation created: {$outputPath}\n";
        
        // Update the index after creating each documentation file
        $this->updateDocumentationIndex($outputPath, $outputDir);

        // Rate limiting to avoid hitting API limits
        usleep(500000); // 0.5 seconds
    }

    /**
     * Update the documentation index file
     * 
     * @param string $documentPath Path to the newly created document
     * @param string $outputDir Base directory for documentation
     */
    private function updateDocumentationIndex(string $documentPath, string $outputDir): void
    {
        $indexPath = $outputDir . "index.md";
        $relPath = substr($documentPath, strlen($outputDir));
        
        // Replace backslashes with forward slashes for compatibility
        $relPath = str_replace('\\', '/', $relPath);

        // Create a new index file if it doesn't exist
        if (!file_exists($indexPath)) {
            $indexContent = "# Documentation Index\n\n";
            $indexContent .= "This index is automatically generated and lists all documentation files:\n\n";
            file_put_contents($indexPath, $indexContent);
        }
        
        // Get all documentation files
        $allDocs = $this->getAllDocumentationFiles($outputDir);
        
        // Build index content
        $indexContent = "# Documentation Index\n\n";
        $indexContent .= "This index is automatically generated and lists all documentation files:\n\n";
        
        // Build a nested structure of directories and files
        $tree = [];
        foreach ($allDocs as $file) {
            if (basename($file) === 'index.md') continue; // Skip index.md itself
            
            $relFilePath = substr($file, strlen($outputDir));
            $relFilePath = str_replace('\\', '/', $relFilePath); // Ensure forward slashes
            $pathParts = explode('/', trim($relFilePath, '/'));
            
            // Add to tree structure
            $this->addToTree($tree, $pathParts, $file, $outputDir);
        }
        
        // Generate nested markdown from tree
        $indexContent .= $this->generateNestedMarkdown($tree, $outputDir);
        
        file_put_contents($indexPath, $indexContent);
        echo "Index updated: {$indexPath}\n";
    }

    /**
     * Normalize a string for Table of Contents links
     */
    private function normalizeForToc(string $text): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/', '-', trim($text)));
    }

    /**
     * Add a file to the nested tree structure
     * 
     * @param array &$tree Reference to the tree structure
     * @param array $pathParts Path components
     * @param string $file Full path to the file
     * @param string $outputDir Output directory path
     */
    private function addToTree(array &$tree, array $pathParts, string $file, string $outputDir): void
    {
        if (count($pathParts) === 1) {
            // This is a file in the current level
            $tree['_files'][] = [
                'path' => $file,
                'name' => $pathParts[0],
                'title' => $this->getDocumentTitle($file),
                'relPath' => substr($file, strlen($outputDir))
            ];
            return;
        }
        
        // This is a directory
        $dirName = $pathParts[0];
        if (!isset($tree[$dirName])) {
            $tree[$dirName] = [];
        }
        
        // Process the rest of the path
        array_shift($pathParts);
        $this->addToTree($tree[$dirName], $pathParts, $file, $outputDir);
    }
    
    /**
     * Generate nested markdown from the tree structure
     * 
     * @param array $tree The tree structure
     * @param string $outputDir Output directory path
     * @param int $level Current nesting level (for indentation)
     * @return string Markdown content
     */
    private function generateNestedMarkdown(array $tree, string $outputDir, int $level = 0): string
    {
        $markdown = '';
        $indent = str_repeat('  ', $level); // 2 spaces per level for indentation
        
        // First output directories (sorted alphabetically)
        $dirs = array_keys($tree);
        sort($dirs);
        
        foreach ($dirs as $dir) {
            if ($dir === '_files') continue; // Skip the files array, process it last
            
            $markdown .= "{$indent}* **{$dir}/**\n";
            $markdown .= $this->generateNestedMarkdown($tree[$dir], $outputDir, $level + 1);
        }
        
        // Then output files in the current directory level
        if (isset($tree['_files'])) {
            // Sort files by name
            usort($tree['_files'], function($a, $b) {
                return $a['name'] <=> $b['name'];
            });
            
            foreach ($tree['_files'] as $file) {
                $title = $file['title'];
                $relPath = $file['relPath'];
                $markdown .= "{$indent}* [{$title}]({$relPath})\n";
            }
        }
        
        return $markdown;
    }

    /**
     * Get the title of a markdown document
     * 
     * @param string $filePath Path to the markdown file
     * @return string The title or fallback to filename
     */
    private function getDocumentTitle(string $filePath): string
    {
        if (!file_exists($filePath)) {
            return basename($filePath);
        }
        
        $content = file_get_contents($filePath);
        // Try to find the first heading
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }
        
        return pathinfo($filePath, PATHINFO_FILENAME);
    }
    
    /**
     * Get all documentation files in the output directory
     * 
     * @param string $outputDir The documentation output directory
     * @return array List of markdown files
     */
    private function getAllDocumentationFiles(string $outputDir): array
    {
        $files = [];
        
        if (!is_dir($outputDir)) {
            return $files;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $outputDir,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }

    /**
     * Process all files in directory recursively
     */
    private function processDirectory($baseDir): void
    {
        $baseDir = rtrim($baseDir, "/");

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $baseDir,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            // Skip directories
            if ($file->isDir()) {
                continue;
            }

            $sourcePath = $file->getPathname();
            $dirName = basename(dirname($sourcePath));
            $fileName = $file->getBasename();

            // Skip hidden files and directories
            if (strpos($fileName, ".") === 0 || strpos($dirName, ".") === 0) {
                continue;
            }

            // Calculate relative path from the source directory
            $relFilePath = substr($sourcePath, strlen($baseDir) + 1);

            // Check if parent directory should be processed
            $relDirPath = dirname($relFilePath);
            if (!$this->shouldProcessDirectory($relDirPath)) {
                continue;
            }

            $this->createDocumentationFile($sourcePath, $relFilePath, $baseDir);
        }
    }

    /**
     * Main method to execute the documentation generation
     */
    public function generate(): void
    {
        // Ensure output directory exists
        $this->ensureDirectoryExists($this->outputDir);

        // Process each source directory
        foreach ($this->sourceDirs as $sourceDir) {
            if (file_exists($sourceDir)) {
                echo "Processing directory: {$sourceDir}\n";
                $this->processDirectory($sourceDir);
            } else {
                echo "Directory not found: {$sourceDir}\n";
            }
        }
        
        // Make sure the index is fully up to date
        $this->finalizeDocumentationIndex();

        echo "\nDocumentation generation complete! Files are available in the '{$this->outputDir}' directory.\n";
    }
    
    /**
     * Finalize the documentation index to ensure it's complete
     */
    private function finalizeDocumentationIndex(): void
    {
        $outputDir = rtrim($this->outputDir, "/") . "/";
        $this->updateDocumentationIndex("", $outputDir);
        echo "Documentation index finalized.\n";
    }
}
