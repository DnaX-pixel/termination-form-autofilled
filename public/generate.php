<?php
require __DIR__ . '/../app/Services/DocxExporter.php';

/**
 * Picks the most common installation address among the given services as
 * the "site address", and appends a remark listing any service numbers
 * whose address differs from it.
 */
function resolveSiteAddress(array $services): string
{
    $counts = [];
    foreach ($services as $s) {
        $addr = trim($s['svc_installation_address'] ?? '');
        if ($addr === '') {
            continue;
        }
        $counts[$addr] = ($counts[$addr] ?? 0) + 1;
    }

    if (empty($counts)) {
        return '';
    }

    arsort($counts);
    $primary = array_key_first($counts);

    $differing = [];
    foreach ($services as $s) {
        $addr = trim($s['svc_installation_address'] ?? '');
        if ($addr !== '' && $addr !== $primary) {
            $differing[] = $s['service_number'] . ': ' . $addr;
        }
    }

    if (empty($differing)) {
        return $primary;
    }

    return $primary . ' (Note: different address for Service Number ' . implode('; ', $differing) . ')';
}

$accountNo = trim($_POST['account_no'] ?? '');
if ($accountNo === '') {
    http_response_code(400);
    echo 'Account number is required.';
    exit;
}

$serviceNumbers = $_POST['service_number'] ?? [];
$addresses = $_POST['svc_installation_address'] ?? [];

$services = [];
foreach ($serviceNumbers as $i => $serviceNumber) {
    $serviceNumber = trim($serviceNumber);
    $address = trim($addresses[$i] ?? '');
    if ($serviceNumber === '') {
        continue; // skip blank rows
    }
    $services[] = [
        'service_number' => $serviceNumber,
        'svc_installation_address' => $address,
    ];
}

if (empty($services)) {
    http_response_code(400);
    echo 'At least one Service Number is required.';
    exit;
}

$fields = [
    'account_no' => $accountNo,
    'Customer_name' => trim($_POST['customer_name'] ?? ''),
    'TM_segment_code' => trim($_POST['tm_segment_code'] ?? ''),
    'IC/BR_no' => trim($_POST['ic_br_no'] ?? ''),
    'SVC_Installation_Address' => resolveSiteAddress($services),
    'date_generated' => date('d/m/Y'),
];

$storageDir = __DIR__ . '/../storage/generated';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0777, true);
}

$exporter = new DocxExporter(__DIR__ . '/../templates/termination-form.docx', $storageDir);

try {
    $docxPath = $exporter->generate($fields, $services);
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

$filename = 'Termination_' . preg_replace('/[^A-Za-z0-9_-]/', '', $accountNo) . '.' . $ext;

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($downloadPath));
readfile($downloadPath);
