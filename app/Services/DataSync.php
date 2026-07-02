<?php

/**
 * Keeps data/raw_data.json in sync with the source xlsx by comparing
 * file modification times. Call ensureFresh() before reading raw_data.json;
 * if the xlsx was replaced/updated since the last import, it re-runs the
 * import automatically.
 */
class DataSync
{
    /**
     * Finds the most recently modified "SERVICE LEVEL*.xlsx" file in $dir,
     * so a new dated export (or an overwrite of the same file) is picked
     * up automatically without any code/config change.
     */
    public static function findLatestSourceFile(string $dir): string
    {
        $matches = glob($dir . '/SERVICE LEVEL*.xlsx');
        // Ignore Office lock files like "~$SERVICE LEVEL....xlsx"
        $matches = array_filter($matches, fn ($p) => !str_contains(basename($p), '~$'));

        if (empty($matches)) {
            throw new RuntimeException("No 'SERVICE LEVEL*.xlsx' file found in $dir");
        }

        usort($matches, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $matches[0];
    }

    private string $pythonBin;

    public function __construct(
        private string $xlsxPath,
        private string $jsonPath,
        private string $importScriptPath,
        ?string $pythonBin = null,
    ) {
        $this->pythonBin = $pythonBin
            ?? getenv('PYTHON_BIN')
            ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    }

    /**
     * @return array{ranImport: bool, output: string}
     */
    public function ensureFresh(): array
    {
        if (!file_exists($this->xlsxPath)) {
            throw new RuntimeException("Source xlsx not found: {$this->xlsxPath}");
        }

        $xlsxTime = filemtime($this->xlsxPath);
        $jsonTime = file_exists($this->jsonPath) ? filemtime($this->jsonPath) : 0;

        if ($jsonTime >= $xlsxTime) {
            return ['ranImport' => false, 'output' => ''];
        }

        return ['ranImport' => true, 'output' => $this->runImport()];
    }

    private function runImport(): string
    {
        // Scanning the full xlsx (55k+ rows) can take well over PHP's
        // default 30s execution limit; this only runs when the source
        // file actually changed, so allow it the time it needs.
        set_time_limit(300);

        $cmd = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg($this->pythonBin),
            escapeshellarg($this->importScriptPath),
            escapeshellarg($this->xlsxPath)
        );

        exec($cmd, $output, $exitCode);
        $text = implode("\n", $output);

        if ($exitCode !== 0) {
            throw new RuntimeException("raw_data import failed: $text");
        }

        return $text;
    }
}
