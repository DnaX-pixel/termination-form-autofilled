<?php
require __DIR__ . '/../app/Services/DataSync.php';

header('Content-Type: application/json');

$serviceNumber = trim($_GET['service_number'] ?? '');

if ($serviceNumber === '') {
    http_response_code(400);
    echo json_encode(['found' => false, 'message' => 'service_number is required']);
    exit;
}

$jsonPath = __DIR__ . '/../data/raw_data.json';
$importScript = __DIR__ . '/../scripts/import_data.py';

try {
    $xlsxPath = DataSync::findLatestSourceFile(__DIR__ . '/..');
    $sync = new DataSync($xlsxPath, $jsonPath, $importScript);
    $sync->ensureFresh();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['found' => false, 'message' => 'Raw data sync failed: ' . $e->getMessage()]);
    exit;
}

$data = json_decode(file_get_contents($jsonPath), true);

$record = $data[$serviceNumber] ?? null;

if ($record === null) {
    http_response_code(404);
    echo json_encode(['found' => false, 'message' => 'Service number not found in raw data.']);
    exit;
}

echo json_encode(['found' => true] + $record);
