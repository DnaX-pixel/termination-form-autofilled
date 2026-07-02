<?php
/**
 * One-time fix: replace the instructional note in the Service Number cell
 * with an actual ${service_number} placeholder so the entered value is
 * echoed back into the generated document.
 */
$path = __DIR__ . '/../templates/termination-form.docx';

$zip = new ZipArchive();
if ($zip->open($path) !== true) {
    fwrite(STDERR, "Failed to open $path\n");
    exit(1);
}

$xml = $zip->getFromName('word/document.xml');
if ($xml === false) {
    fwrite(STDERR, "word/document.xml not found\n");
    exit(1);
}

$needle = '<w:t>End user will key in manually, then other info that have place holder will auto-fill because this is Primary Key</w:t>';
$replacement = '<w:t>${service_number}</w:t>';

$count = 0;
$xml = str_replace($needle, $replacement, $xml, $count);

if ($count !== 1) {
    fwrite(STDERR, "Expected exactly 1 replacement, got $count\n");
    exit(1);
}

$dom = new DOMDocument();
if (@$dom->loadXML($xml) !== true) {
    fwrite(STDERR, "Resulting XML is not well-formed\n");
    exit(1);
}

$zip->deleteName('word/document.xml');
$zip->addFromString('word/document.xml', $xml);
$zip->close();

echo "OK: replaced $count occurrence(s). Template updated.\n";
