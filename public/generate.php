<?php
require __DIR__ . '/../app/Services/DocxExporter.php';

$serviceNumber = trim($_POST['service_number'] ?? '');
if ($serviceNumber === '') {
    http_response_code(400);
    echo 'Service number is required.';
    exit;
}

$fields = [
    'service_number' => $serviceNumber,
    'account_no' => trim($_POST['account_no'] ?? ''),
    'Customer_name' => trim($_POST['customer_name'] ?? ''),
    'TM_segment_code' => trim($_POST['tm_segment_code'] ?? ''),
    'IC/BR_no' => trim($_POST['ic_br_no'] ?? ''),
    'SVC_Installation_Address' => trim($_POST['svc_installation_address'] ?? ''),
    'date_generated' => date('d/m/Y'),
];

$storageDir = __DIR__ . '/../storage/generated';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0777, true);
}

$exporter = new DocxExporter(__DIR__ . '/../templates/termination-form.docx', $storageDir);

try {
    $docxPath = $exporter->generate($fields);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Failed to generate document: ' . htmlspecialchars($e->getMessage());
    exit;
}

$format = $_POST['format'] ?? 'pdf';

if ($format === 'docx') {
    $downloadPath = $docxPath;
    $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    $ext = 'docx';
} else {
    try {
        $downloadPath = $exporter->toPdf($docxPath);
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Failed to convert to PDF: ' . htmlspecialchars($e->getMessage());
        exit;
    }
    $mime = 'application/pdf';
    $ext = 'pdf';
}

$filename = 'Termination_' . preg_replace('/[^A-Za-z0-9_-]/', '', $serviceNumber) . '.' . $ext;

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($downloadPath));
readfile($downloadPath);
