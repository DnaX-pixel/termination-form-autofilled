<?php

class DocxExporter
{
    private string $sofficePath;

    public function __construct(
        private string $templatePath,
        private string $storageDir,
        ?string $sofficePath = null,
    ) {
        $this->sofficePath = $sofficePath
            ?? getenv('SOFFICE_BIN')
            ?: (PHP_OS_FAMILY === 'Windows'
                ? 'C:\\Program Files\\LibreOffice\\program\\soffice.exe'
                : 'soffice');
    }

    /**
     * Fill the template with the given fields and return the path to the
     * generated .docx file.
     *
     * @param array<string,string> $fields placeholder name => value (without ${})
     */
    public function generate(array $fields): string
    {
        $docxPath = $this->storageDir . '/' . uniqid('termination_', true) . '.docx';
        if (!copy($this->templatePath, $docxPath)) {
            throw new RuntimeException("Failed to copy template to $docxPath");
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new RuntimeException("Failed to open $docxPath as zip");
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            throw new RuntimeException('word/document.xml not found in template');
        }

        foreach ($fields as $name => $value) {
            $xml = $this->replacePlaceholder($xml, $name, (string) $value);
        }

        $this->assertWellFormed($xml);

        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $docxPath;
    }

    public function toPdf(string $docxPath): string
    {
        $outDir = dirname($docxPath);
        $cmd = sprintf(
            '%s --headless --convert-to pdf --outdir %s %s',
            escapeshellarg($this->sofficePath),
            escapeshellarg($outDir),
            escapeshellarg($docxPath)
        );

        exec($cmd . ' 2>&1', $output, $exitCode);

        $pdfPath = preg_replace('/\.docx$/', '.pdf', $docxPath);
        if ($exitCode !== 0 || !file_exists($pdfPath)) {
            throw new RuntimeException('LibreOffice conversion failed: ' . implode("\n", $output));
        }

        return $pdfPath;
    }

    /**
     * Replace ${name} with $value inside word/document.xml, tolerating
     * Word splitting the placeholder text across multiple adjacent <w:t>
     * runs (which happens unpredictably after any edit to the document).
     */
    private function replacePlaceholder(string $xml, string $name, string $value): string
    {
        $literal = '${' . $name . '}';
        $charsPattern = $this->buildFlexiblePattern($literal);

        // Groups: 1 = opening <w:r>+rPr, 2 = leading text sharing the first
        // <w:t> with the placeholder, 3 = trailing text sharing the last
        // <w:t> with the placeholder (Word sometimes keeps surrounding
        // text like "Date: ${x}" in the same run/text node).
        $pattern = '/(<w:r\b[^>]*>(?:<w:rPr>(?:(?!<\/w:rPr>).)*?<\/w:rPr>)?)'
            . '<w:t\b[^>]*>([^<]*?)' . $charsPattern . '([^<]*?)<\/w:t><\/w:r>/su';

        $escapedValue = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $result = preg_replace_callback($pattern, function ($m) use ($escapedValue) {
            return $m[1] . '<w:t xml:space="preserve">' . $m[2] . $escapedValue . $m[3] . '</w:t></w:r>';
        }, $xml, -1, $count);

        if ($count === 0) {
            throw new RuntimeException("Placeholder \${$name} not found in template");
        }

        return $result;
    }

    /**
     * Build a regex matching $literal's characters in sequence, allowing
     * (but not requiring) a run/text-node boundary between any two
     * characters.
     */
    private function buildFlexiblePattern(string $literal): string
    {
        $chars = preg_split('//u', $literal, -1, PREG_SPLIT_NO_EMPTY);
        $boundary = '(?:<\/w:t>(?:<\/w:r>)?(?:<w:r\b[^>]*>)?'
            . '(?:<w:rPr>(?:(?!<\/w:rPr>).)*?<\/w:rPr>)?<w:t\b[^>]*>)?';

        $parts = array_map(fn ($c) => preg_quote($c, '/'), $chars);

        return implode($boundary, $parts);
    }

    private function assertWellFormed(string $xml): void
    {
        $dom = new DOMDocument();
        $ok = @$dom->loadXML($xml, LIBXML_NONET);
        if ($ok !== true) {
            throw new RuntimeException('Generated word/document.xml is not well-formed');
        }
    }
}
