<?php

namespace tests;

use Docudoodle\Docudoodle;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\TestCase;

class CachingTest extends TestCase
{
    use PHPMock; // Use the trait for easy mock management

    private string $tempSourceDir;
    private string $tempOutputDir;
    private string $tempCacheFile;

    protected function setUp(): void
    {
        parent::setUp();
        // Create temporary directories for source and output
        $this->tempSourceDir = sys_get_temp_dir() . '/' . uniqid('docudoodle_test_source_');
        $this->tempOutputDir = sys_get_temp_dir() . '/' . uniqid('docudoodle_test_output_');
        mkdir($this->tempSourceDir, 0777, true);
        mkdir($this->tempOutputDir, 0777, true);

        // Default cache file location for setup
        $this->tempCacheFile = $this->tempOutputDir . '/.docudoodle_cache.json';
    }

    protected function tearDown(): void
    {
        // Clean up temporary directories and files
        $this->deleteDirectory($this->tempSourceDir);
        $this->deleteDirectory($this->tempOutputDir);
        // Attempt to delete cache file if it exists outside output dir in some tests
        if ($this->tempCacheFile && file_exists($this->tempCacheFile) && strpos($this->tempCacheFile, $this->tempOutputDir) !== 0) {
             @unlink($this->tempCacheFile);
        }
        parent::tearDown();
    }

    // Helper function to recursively delete a directory
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->deleteDirectory("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    // Helper to create a dummy source file
    private function createSourceFile(string $relativePath, string $content): string
    {
        $fullPath = $this->tempSourceDir . '/' . $relativePath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, $content);
        return $fullPath;
    }

    // Helper to get Docudoodle instance with basic config
    private function getGenerator(
        bool $useCache = true,
        ?string $cacheFilePath = null,
        bool $forceRebuild = false,
        string $model = 'test-model',
        string $promptTemplatePath = __DIR__ . '/../../resources/templates/default-prompt.md'
    ): Docudoodle // Return a real instance now
    {
        // Use a very simple model/API key for testing
        return new Docudoodle(
            openaiApiKey: 'test-key',
            sourceDirs: [$this->tempSourceDir],
            outputDir: $this->tempOutputDir,
            model: $model,
            maxTokens: 100,
            allowedExtensions: ['php'],
            skipSubdirectories: [],
            apiProvider: 'openai', // Assume basic provider for test structure
            ollamaHost: 'localhost',
            ollamaPort: 5000,
            promptTemplate: $promptTemplatePath,
            useCache: $useCache,
            cacheFilePath: $cacheFilePath ?? $this->tempCacheFile, // Pass explicitly
            forceRebuild: $forceRebuild
        );
    }

    // --- Test Cases ---

    public function testSkipsUnchangedFiles(): void
    {
        // Define Mocks for curl functions within Docudoodle namespace
        $curlExecMock = $this->getFunctionMock('Docudoodle', 'curl_exec');
        $curlErrnoMock = $this->getFunctionMock('Docudoodle', 'curl_errno');
        $curlErrorMock = $this->getFunctionMock('Docudoodle', 'curl_error');
        // Mock curl_close to do nothing
        $this->getFunctionMock('Docudoodle', 'curl_close')->expects($this->any());
        // Mock curl_init to return a dummy resource (or handle)
        $this->getFunctionMock('Docudoodle', 'curl_init')->expects($this->any())->willReturn(curl_init()); // Return a real dummy handle
        // Mock curl_setopt_array/curl_setopt to do nothing (or check options if needed)
        $this->getFunctionMock('Docudoodle', 'curl_setopt_array')->expects($this->any());

        // Configure mock responses
        $mockApiResponse = json_encode([
            'choices' => [
                ['message' => ['content' => 'Mocked AI Response']]
            ]
        ]);
        $curlExecMock->expects($this->any())->willReturn($mockApiResponse);
        $curlErrnoMock->expects($this->any())->willReturn(0);
        $curlErrorMock->expects($this->any())->willReturn('');

        // 1. Create source file
        $sourcePath = $this->createSourceFile('test.php', '<?php echo "Hello";');
        $docPath = $this->tempOutputDir . '/' . basename($this->tempSourceDir) . '/test.md'; // Correct path calculation
        $cachePath = $this->tempCacheFile;

        // 2. Run generator (first run)
        $generator1 = $this->getGenerator();
        $generator1->generate();

        // 3. Assert doc file exists, cache file exists
        $this->assertFileExists($docPath, 'Documentation file should be created on first run.');
        $this->assertFileExists($cachePath, 'Cache file should be created on first run.');
        $initialDocModTime = filemtime($docPath);

        // Wait briefly to ensure file modification times can differ
        usleep(10000); // 10ms should be enough

        // 4. Run generator again
        $generator2 = $this->getGenerator(); // Get a fresh instance
        // Capture output to check for "Skipping" message
        ob_start();
        $generator2->generate();
        $output = ob_get_clean();

        // 5. Assert generator indicates skipping
        $this->assertStringContainsString('Skipping unchanged file', $output, 'Generator output should indicate skipping.');
        $this->assertStringContainsString($sourcePath, $output, 'Generator output should mention the skipped file path.');

        // 6. Assert doc file timestamp hasn't changed
        $this->assertFileExists($docPath); // Make sure it wasn't deleted
        $finalDocModTime = filemtime($docPath);
        $this->assertEquals($initialDocModTime, $finalDocModTime, 'Documentation file modification time should not change on second run.');
    }

    public function testReprocessesChangedFiles(): void
    {
        // Define Mocks for curl functions
        $curlExecMock = $this->getFunctionMock('Docudoodle', 'curl_exec');
        $curlErrnoMock = $this->getFunctionMock('Docudoodle', 'curl_errno');
        $curlErrorMock = $this->getFunctionMock('Docudoodle', 'curl_error');
        $this->getFunctionMock('Docudoodle', 'curl_close')->expects($this->any());
        $this->getFunctionMock('Docudoodle', 'curl_init')->expects($this->any())->willReturn(curl_init());
        $this->getFunctionMock('Docudoodle', 'curl_setopt_array')->expects($this->any());

        $curlExecMock->expects($this->atLeastOnce())
                     ->willReturnCallback(function() {
                         return json_encode([
                             'choices' => [
                                 ['message' => ['content' => 'Mocked AI Response @ ' . microtime(true)]]
                             ]
                         ]);
                     });
        $curlErrnoMock->expects($this->any())->willReturn(0);
        $curlErrorMock->expects($this->any())->willReturn('');

        // 1. Create source file
        $sourcePath = $this->createSourceFile('test.php', '<?php echo "Version 1";');
        $docPath = $this->tempOutputDir . '/' . basename($this->tempSourceDir) . '/test.md';
        $cachePath = $this->tempCacheFile;
        $initialHash = sha1_file($sourcePath);

        // 2. Run generator
        $generator1 = $this->getGenerator();
        $generator1->generate();

        // 3. Get initial state
        $this->assertFileExists($docPath);
        $this->assertFileExists($cachePath);
        $initialDocModTime = filemtime($docPath);
        $initialCacheData = json_decode(file_get_contents($cachePath), true);
        $this->assertEquals($initialHash, $initialCacheData[$sourcePath] ?? null, 'Initial cache should contain correct hash.');

        // Wait briefly
        usleep(10000);

        // 4. Modify source file
        $this->createSourceFile('test.php', '<?php echo "Version 2 - Changed";');
        $newHash = sha1_file($sourcePath);
        $this->assertNotEquals($initialHash, $newHash, 'File hashes should differ after modification.');

        // Update mock response for second run if needed (optional, depends on assertion needs)
        $curlExecMock->expects($this->exactly(2))->willReturnOnConsecutiveCalls(
            json_encode([
                'choices' => [
                    ['message' => ['content' => 'Mocked AI Response @ ' . microtime(true)]]
                ]
            ]),
            json_encode([
                'choices' => [
                    ['message' => ['content' => 'Mocked AI Response @ ' . microtime(true)]]
                ]
            ])
        );

        // 5. Run generator again
        $generator2 = $this->getGenerator();
        // Capture output to ensure it DOES NOT say skipping
        ob_start();
        $generator2->generate();
        $output = ob_get_clean();

        // Assert it didn't skip
        $this->assertStringNotContainsString('Skipping unchanged file', $output, 'Generator output should NOT indicate skipping.');
        $this->assertStringContainsString('Generating documentation', $output, 'Generator output should indicate generation.');

        // 6. Assert doc file mod time/hash changed
        $this->assertFileExists($docPath);
        clearstatcache(); // Clear stat cache before checking filemtime again
        $finalDocModTime = filemtime($docPath);
        $this->assertGreaterThan($initialDocModTime, $finalDocModTime, 'Documentation file modification time should update on second run.');

        // 7. Assert cache file content updated with new hash
        $this->assertFileExists($cachePath);
        $finalCacheData = json_decode(file_get_contents($cachePath), true);
        $this->assertEquals($newHash, $finalCacheData[$sourcePath] ?? null, 'Cache should be updated with the new hash.');
        $this->assertEquals($initialCacheData['_config_hash'] ?? null, $finalCacheData['_config_hash'] ?? null, 'Config hash should not change.');
    }

    public function testProcessesNewFiles(): void
    {
        // Define Mocks for curl functions
        $curlExecMock = $this->getFunctionMock('Docudoodle', 'curl_exec');
        $curlErrnoMock = $this->getFunctionMock('Docudoodle', 'curl_errno');
        $curlErrorMock = $this->getFunctionMock('Docudoodle', 'curl_error');
        $this->getFunctionMock('Docudoodle', 'curl_close')->expects($this->any());
        $this->getFunctionMock('Docudoodle', 'curl_init')->expects($this->any())->willReturn(curl_init());
        $this->getFunctionMock('Docudoodle', 'curl_setopt_array')->expects($this->any());

        $curlExecMock->expects($this->atLeastOnce())
                     ->willReturnCallback(function() {
                         return json_encode([
                             'choices' => [
                                 ['message' => ['content' => 'Mocked AI Response @ ' . microtime(true)]]
                             ]
                         ]);
                     });
        $curlErrnoMock->expects($this->any())->willReturn(0);
        $curlErrorMock->expects($this->any())->willReturn('');

        // 1. Create source file A
        $sourcePathA = $this->createSourceFile('fileA.php', '<?php echo "File A v1";');
        $docPathA = $this->tempOutputDir . '/' . basename($this->tempSourceDir) . '/fileA.md';
        $cachePath = $this->tempCacheFile;
        $hashA = sha1_file($sourcePathA);

        // 2. Run generator (first run)
        $generator1 = $this->getGenerator();
        $generator1->generate();

        // 3. Assert doc A exists
        $this->assertFileExists($docPathA, 'Doc A should exist after first run.');
        $this->assertFileExists($cachePath, 'Cache should exist after first run.');
        $initialDocAModTime = filemtime($docPathA);
        $cacheData1 = json_decode(file_get_contents($cachePath), true);
        $this->assertEquals($hashA, $cacheData1[$sourcePathA] ?? null, 'Cache should contain hash for file A.');
        $this->assertCount(2, $cacheData1, 'Cache should have 2 entries (config + file A).'); // Config hash + File A

        // Wait briefly
        usleep(10000);

        // 4. Create source file B
        $sourcePathB = $this->createSourceFile('fileB.php', '<?php echo "File B v1";');
        $docPathB = $this->tempOutputDir . '/' . basename($this->tempSourceDir) . '/fileB.md';
        $hashB = sha1_file($sourcePathB);

        // 5. Run generator again
        $generator2 = $this->getGenerator();
        // Capture output
        ob_start();
        $generator2->generate();
        $output = ob_get_clean();

        // Assert file A was skipped and file B was generated
        $this->assertStringContainsString("Skipping unchanged file: {$sourcePathA}", $output, 'Generator should skip file A.');
        $this->assertStringContainsString("Generating documentation for {$sourcePathB}", $output, 'Generator should generate file B.');

        // 6. Assert doc B exists
        $this->assertFileExists($docPathB, 'Doc B should exist after second run.');

        // 7. Assert doc A was likely skipped (check mod time?)
        clearstatcache();
        $finalDocAModTime = filemtime($docPathA);
        $this->assertEquals($initialDocAModTime, $finalDocAModTime, 'Doc A modification time should not change.');

        // 8. Assert cache contains both A and B
        $this->assertFileExists($cachePath);
        $cacheData2 = json_decode(file_get_contents($cachePath), true);
        $this->assertEquals($hashA, $cacheData2[$sourcePathA] ?? null, 'Cache should still contain correct hash for file A.');
        $this->assertEquals($hashB, $cacheData2[$sourcePathB] ?? null, 'Cache should now contain hash for file B.');
        $this->assertCount(3, $cacheData2, 'Cache should have 3 entries (config + file A + file B).'); // Config hash + File A + File B
        $this->assertEquals($cacheData1['_config_hash'] ?? null, $cacheData2['_config_hash'] ?? null, 'Config hash should not change.');
    }

    public function testOrphanCleanup(): void
    {
        // Define Mocks for curl functions
        $curlExecMock = $this->getFunctionMock('Docudoodle', 'curl_exec');
        $curlErrnoMock = $this->getFunctionMock('Docudoodle', 'curl_errno');
        $curlErrorMock = $this->getFunctionMock('Docudoodle', 'curl_error');
        $this->getFunctionMock('Docudoodle', 'curl_close')->expects($this->any());
        $this->getFunctionMock('Docudoodle', 'curl_init')->expects($this->any())->willReturn(curl_init());
        $this->getFunctionMock('Docudoodle', 'curl_setopt_array')->expects($this->any());

        $curlExecMock->expects($this->atLeastOnce())
                     ->willReturnCallback(function() {
                         return json_encode([
                             'choices' => [
                                 ['message' => ['content' => 'Mocked AI Response @ ' . microtime(true)]]
                             ]
                         ]);
                     });
        $curlErrnoMock->expects($this->any())->willReturn(0);
        $curlErrorMock->expects($this->any())->willReturn('');

        // 1. Create source files A and B
        $sourcePathA = $this->createSourceFile('fileA.php', '<?php echo "File A";');
        $sourcePathB = $this->createSourceFile('fileB.php', '<?php echo "File B";');
        $docPathA = $this->tempOutputDir . '/' . basename($this->tempSourceDir) . '/fileA.md';
        $docPathB = $this->tempOutputDir . '/' . basename($this->tempSourceDir) . '/fileB.md';
        $cachePath = $this->tempCacheFile;
        $hashA = sha1_file($sourcePathA);
        $hashB = sha1_file($sourcePathB);

        // 2. Run generator
        $generator1 = $this->getGenerator();
        $generator1->generate();

        // 3. Assert docs A and B exist, cache contains A and B
        $this->assertFileExists($docPathA, 'Doc A should exist initially.');
        $this->assertFileExists($docPathB, 'Doc B should exist initially.');
        $this->assertFileExists($cachePath, 'Cache should exist initially.');
        $cacheData1 = json_decode(file_get_contents($cachePath), true);
        $this->assertArrayHasKey($sourcePathA, $cacheData1);
        $this->assertArrayHasKey($sourcePathB, $cacheData1);
        $this->assertEquals($hashA, $cacheData1[$sourcePathA]);
        $this->assertEquals($hashB, $cacheData1[$sourcePathB]);
        $this->assertCount(3, $cacheData1); // config + A + B

        // 4. Delete source file A
        unlink($sourcePathA);
        $this->assertFileDoesNotExist($sourcePathA, 'Source file A should be deleted.');

        // Wait briefly
        usleep(10000);

        // 5. Run generator again
        $generator2 = $this->getGenerator();
        ob_start();
        $generator2->generate();
        $output = ob_get_clean();

        // 7. Assert output indicates cleanup and deletion
        $this->assertStringContainsString('Cleaning up documentation for deleted source files', $output, 'Generator should mention cleanup.');
        $this->assertStringContainsString("Deleting orphan documentation: {$docPathA}", $output, 'Generator should mention deleting orphan doc A.');
        $this->assertStringContainsString("Skipping unchanged file: {$sourcePathB}", $output, 'Generator should skip file B.');

        // 6. Assert doc A does not exist
        $this->assertFileDoesNotExist($docPathA, 'Doc A should be deleted after cleanup.');

        // 8. Assert doc B still exists
        $this->assertFileExists($docPathB, 'Doc B should still exist.');

        // 9. Assert cache contains only B
        $this->assertFileExists($cachePath, 'Cache should still exist.');
        $cacheData2 = json_decode(file_get_contents($cachePath), true);
        $this->assertArrayNotHasKey($sourcePathA, $cacheData2, 'Cache should not contain file A after cleanup.');
        $this->assertArrayHasKey($sourcePathB, $cacheData2, 'Cache should still contain file B.');
        $this->assertEquals($hashB, $cacheData2[$sourcePathB], 'Cache should have correct hash for file B.');
        $this->assertCount(2, $cacheData2, 'Cache should have 2 entries (config + file B).'); // config + B
        $this->assertEquals($cacheData1['_config_hash'] ?? null, $cacheData2['_config_hash'] ?? null, 'Config hash should not change during orphan cleanup.');
    }

    public function testConfigurationChangeInvalidation(): void
    {
        // Define Mocks for curl functions
        $curlExecMock = $this->getFunctionMock('Docudoodle', 'curl_exec');
        $curlErrnoMock = $this->getFunctionMock('Docudoodle', 'curl_errno');
        $curlErrorMock = $this->getFunctionMock('Docudoodle', 'curl_error');
        $this->getFunctionMock('Docudoodle', 'curl_close')->expects($this->any());
        $this->getFunctionMock('Docudoodle', 'curl_init')->expects($this->any())->willReturn(curl_init());
        $this->getFunctionMock('Docudoodle', 'curl_setopt_array')->expects($this->any());

        $curlExecMock->expects($this->atLeastOnce())
                     ->willReturnCallback(function() {
                         return json_encode([
                             'choices' => [
                                 ['message' => ['content' => 'Mocked AI Response @ ' . microtime(true)]]
                             ]
                         ]);
                     });
        $curlErrnoMock->expects($this->any())->willReturn(0);
        $curlErrorMock->expects($this->any())->willReturn('');

        // 1. Create source file
        $sourcePath = $this->createSourceFile('test.php', '<?php echo "Consistent Content";');
        $docPath = $this->tempOutputDir . '/' . basename($this->tempSourceDir) . '/test.md';
        $cachePath = $this->tempCacheFile;
        $fileHash = sha1_file($sourcePath);

        // 2. Run generator with config X (model-v1)
        $generator1 = $this->getGenerator(model: 'model-v1');
        ob_start(); // Capture initial output to check later
        $generator1->generate();
        ob_end_clean(); // Discard initial output for now

        // 3. Get initial state
        $this->assertFileExists($docPath, 'Doc file should exist after first run.');
        $this->assertFileExists($cachePath, 'Cache file should exist after first run.');
        $initialDocModTime = filemtime($docPath);
        $cacheData1 = json_decode(file_get_contents($cachePath), true);
        $initialConfigHash = $cacheData1['_config_hash'] ?? null;
        $this->assertNotNull($initialConfigHash, 'Initial config hash should be set.');
        $this->assertEquals($fileHash, $cacheData1[$sourcePath] ?? null, 'Initial cache should have file hash.');

        // Wait briefly
        usleep(10000);

        // 4. Run generator again with config Y (model-v2)
        // Pass a different model name to trigger config hash change
        $generator2 = $this->getGenerator(model: 'model-v2');
        ob_start();
        $generator2->generate();
        $output = ob_get_clean();

        // 5. Assert output indicates invalidation and reprocessing
        $this->assertStringContainsString('Configuration changed or cache invalidated', $output, 'Generator should indicate config change.');
        $this->assertStringContainsString('Forcing full documentation rebuild', $output, 'Generator should indicate forcing rebuild.');
        $this->assertStringContainsString("Generating documentation for {$sourcePath}", $output, 'Generator should re-generate the file.');
        $this->assertStringNotContainsString('Skipping unchanged file', $output, 'Generator should not skip the file despite unchanged content.');

        // 6. Assert doc file mod time/hash changed (indicating reprocessing)
        $this->assertFileExists($docPath); // Still exists
        clearstatcache();
        $finalDocModTime = filemtime($docPath);
        $this->assertGreaterThan($initialDocModTime, $finalDocModTime, 'Doc file modification time should update due to reprocessing.');

        // 7. Assert cache config hash updated
        $this->assertFileExists($cachePath);
        $cacheData2 = json_decode(file_get_contents($cachePath), true);
        $finalConfigHash = $cacheData2['_config_hash'] ?? null;
        $this->assertNotNull($finalConfigHash, 'Final config hash should be set.');
        $this->assertNotEquals($initialConfigHash, $finalConfigHash, 'Config hash should change after config modification.');

        // 8. Assert file hash is still present (re-added after reprocessing)
        $this->assertEquals($fileHash, $cacheData2[$sourcePath] ?? null, 'Cache should contain correct file hash after reprocessing.');
        $this->assertCount(2, $cacheData2); // config hash + file hash
    }

    public function testForceRebuildFlag(): void
    {
        // Define Mocks for curl functions
        $curlExecMock = $this->getFunctionMock('Docudoodle', 'curl_exec');
        $curlErrnoMock = $this->getFunctionMock('Docudoodle', 'curl_errno');
        $curlErrorMock = $this->getFunctionMock('Docudoodle', 'curl_error');
        $this->getFunctionMock('Docudoodle', 'curl_close')->expects($this->any());
        $this->getFunctionMock('Docudoodle', 'curl_init')->expects($this->any())->willReturn(curl_init());
        $this->getFunctionMock('Docudoodle', 'curl_setopt_array')->expects($this->any());

        // Configure mock responses with a callback to include a timestamp
        $curlExecMock->expects($this->atLeastOnce()) // Expect at least one call
                     ->willReturnCallback(function() {
                         return json_encode([
                             'choices' => [
                                 ['message' => ['content' => 'Mocked AI Response @ ' . microtime(true)]]
                             ]
                         ]);
                     });
        $curlErrnoMock->expects($this->any())->willReturn(0);
        $curlErrorMock->expects($this->any())->willReturn('');

        // 1. Create source file
        $sourcePath = $this->createSourceFile('test.php', '<?php echo "Content";');
        $docPath = $this->tempOutputDir . '/' . basename($this->tempSourceDir) . '/test.md';
        $cachePath = $this->tempCacheFile;
        $fileHash = sha1_file($sourcePath);

        // 2. Run generator normally
        $generator1 = $this->getGenerator();
        $generator1->generate();

        // 3. Get initial state
        $this->assertFileExists($docPath, 'Doc file should exist after first run.');
        $initialDocContent = file_get_contents($docPath); // Get initial content
        $this->assertFileExists($cachePath, 'Cache file should exist after first run.');
        $cacheData1 = json_decode(file_get_contents($cachePath), true);
        $this->assertEquals($fileHash, $cacheData1[$sourcePath] ?? null);

        // Wait briefly
        usleep(10000);

        // 4. Run generator again with forceRebuild = true
        $generator2 = $this->getGenerator(forceRebuild: true);
        ob_start();
        $generator2->generate();
        $output = ob_get_clean();

        // 5. Assert output indicates rebuild and generation (not skipping)
        $this->assertStringContainsString('Cache will be rebuilt', $output, 'Generator should indicate cache rebuild.');
        $this->assertStringContainsString("Generating documentation for {$sourcePath}", $output, 'Generator should re-generate the file on force rebuild.');
        $this->assertStringNotContainsString('Skipping unchanged file', $output, 'Generator should not skip the file on force rebuild.');

        // 6. Assert doc file content changed (indicating reprocessing)
        $this->assertFileExists($docPath);
        clearstatcache(); // Might not be strictly needed for content check, but good practice
        $finalDocContent = file_get_contents($docPath); // Get final content
        $this->assertNotEquals($initialDocContent, $finalDocContent, 'Doc file content should change on force rebuild due to mock timestamp.');

        // 7. Assert cache is updated correctly (still contains the hash)
        $this->assertFileExists($cachePath);
        $cacheData2 = json_decode(file_get_contents($cachePath), true);
        $this->assertEquals($fileHash, $cacheData2[$sourcePath] ?? null, 'Cache should still contain correct file hash after force rebuild.');
        $this->assertArrayHasKey('_config_hash', $cacheData2, 'Cache should contain config hash after force rebuild.');
        $this->assertCount(2, $cacheData2); // config hash + file hash
    }

    public function testCacheDefaultLocation(): void
    {
        // Define Mocks for curl functions (only need to run once)
        $curlExecMock = $this->getFunctionMock('Docudoodle', 'curl_exec');
        $curlErrnoMock = $this->getFunctionMock('Docudoodle', 'curl_errno');
        $curlErrorMock = $this->getFunctionMock('Docudoodle', 'curl_error');
        $this->getFunctionMock('Docudoodle', 'curl_close')->expects($this->any());
        $this->getFunctionMock('Docudoodle', 'curl_init')->expects($this->any())->willReturn(curl_init());
        $this->getFunctionMock('Docudoodle', 'curl_setopt_array')->expects($this->any());

        $mockApiResponse = json_encode([
            'choices' => [
                ['message' => ['content' => 'Mocked AI Response']]
            ]
        ]);
        $curlExecMock->expects($this->once())->willReturn($mockApiResponse);
        $curlErrnoMock->expects($this->any())->willReturn(0);
        $curlErrorMock->expects($this->any())->willReturn('');

        // 1. Create source file
        $this->createSourceFile('test.php', '<?php echo "Default Cache Test";');
        $expectedDefaultCachePath = $this->tempOutputDir . '/.docudoodle_cache.json';

        // 2. Run generator - Pass null for cacheFilePath to trigger default logic
        // Note: The getGenerator helper itself defaults to $this->tempCacheFile if the arg is null,
        // so we MUST pass null explicitly here to override the helper's internal default.
        $generator = $this->getGenerator(cacheFilePath: null);
        $generator->generate();

        // 3. Assert cache file exists at the default location
        $this->assertFileExists($expectedDefaultCachePath, 'Cache file should be created at the default location.');
    }

    public function testCacheCustomLocationConfig(): void
    {
        // Define Mocks for curl functions
        $curlExecMock = $this->getFunctionMock('Docudoodle', 'curl_exec');
        $curlErrnoMock = $this->getFunctionMock('Docudoodle', 'curl_errno');
        $curlErrorMock = $this->getFunctionMock('Docudoodle', 'curl_error');
        $this->getFunctionMock('Docudoodle', 'curl_close')->expects($this->any());
        $this->getFunctionMock('Docudoodle', 'curl_init')->expects($this->any())->willReturn(curl_init());
        $this->getFunctionMock('Docudoodle', 'curl_setopt_array')->expects($this->any());

        $mockApiResponse = json_encode([
            'choices' => [
                ['message' => ['content' => 'Mocked AI Response']]
            ]
        ]);
        $curlExecMock->expects($this->once())->willReturn($mockApiResponse);
        $curlErrnoMock->expects($this->any())->willReturn(0);
        $curlErrorMock->expects($this->any())->willReturn('');

        // 1. Set custom cache path (outside output dir, but still in temp)
        $customCachePath = sys_get_temp_dir() . '/' . uniqid('docudoodle_custom_cache_') . '.json';
        $this->tempCacheFile = $customCachePath; // Update teardown target
        $this->assertFileDoesNotExist($customCachePath, 'Custom cache file should not exist yet.');

        // 2. Create source file
        $this->createSourceFile('test.php', '<?php echo "Custom Cache Test";');

        // 3. Run generator passing custom path via the helper
        $generator = $this->getGenerator(cacheFilePath: $customCachePath);
        $generator->generate();

        // 4. Assert cache file exists at custom path
        $this->assertFileExists($customCachePath, 'Cache file should be created at the custom location.');
        // Also assert it wasn't created in the default location
        $defaultCachePath = $this->tempOutputDir . '/.docudoodle_cache.json';
        $this->assertFileDoesNotExist($defaultCachePath, 'Cache file should NOT be created at the default location when custom path is given.');
    }

    // Note: Testing command-line override requires a different approach, perhaps testing GenerateDocsCommand itself.

    public function testCacheDisabled(): void
    {
        // Define Mocks for curl functions
        $curlExecMock = $this->getFunctionMock('Docudoodle', 'curl_exec');
        $curlErrnoMock = $this->getFunctionMock('Docudoodle', 'curl_errno');
        $curlErrorMock = $this->getFunctionMock('Docudoodle', 'curl_error');
        $this->getFunctionMock('Docudoodle', 'curl_close')->expects($this->any());
        $this->getFunctionMock('Docudoodle', 'curl_init')->expects($this->any())->willReturn(curl_init());
        $this->getFunctionMock('Docudoodle', 'curl_setopt_array')->expects($this->any());

        // Configure mock response with timestamp to check reprocessing
        $curlExecMock->expects($this->exactly(2)) // Expect two calls since cache is disabled
                     ->willReturnCallback(function() {
                         return json_encode([
                             'choices' => [
                                 ['message' => ['content' => 'Mocked AI Response @ ' . microtime(true)]]
                             ]
                         ]);
                     });
        $curlErrnoMock->expects($this->any())->willReturn(0);
        $curlErrorMock->expects($this->any())->willReturn('');

        // 1. Create source file
        $sourcePath = $this->createSourceFile('test.php', '<?php echo "Cache Disabled Test";');
        $docPath = $this->tempOutputDir . '/' . basename($this->tempSourceDir) . '/test.md';
        $defaultCachePath = $this->tempOutputDir . '/.docudoodle_cache.json';

        // 2. Run generator with useCache = false
        $generator1 = $this->getGenerator(useCache: false);
        $generator1->generate();

        // 3. Assert cache file does NOT exist
        $this->assertFileDoesNotExist($defaultCachePath, 'Cache file should not be created when cache is disabled.');
        $this->assertFileExists($docPath, 'Doc file should be created even with cache disabled.');
        $initialDocContent = file_get_contents($docPath);

        // Wait briefly
        usleep(10000);

        // 4. Run generator again with useCache = false
        $generator2 = $this->getGenerator(useCache: false);
        ob_start();
        $generator2->generate();
        $output = ob_get_clean();

        // 5. Assert cache file still does NOT exist
        $this->assertFileDoesNotExist($defaultCachePath, 'Cache file should still not exist on second run.');

        // 6. Assert it processed again (check doc content change & output)
        $this->assertStringNotContainsString('Skipping unchanged file', $output, 'Generator should not skip when cache disabled.');
        $this->assertStringContainsString('Generating documentation', $output, 'Generator should generate again when cache disabled.');
        clearstatcache();
        $finalDocContent = file_get_contents($docPath);
        $this->assertNotEquals($initialDocContent, $finalDocContent, 'Doc file content should change on second run when cache disabled.');
    }
}
