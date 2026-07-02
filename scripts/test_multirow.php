<?php
require __DIR__ . '/../app/Services/DocxExporter.php';

$services = [
    ['service_number' => '6089889331', 'svc_installation_address' => 'MDLD3823,,LORONG,FAJAR CENTRE,,LAHAD DATU,91100,,MALAYSIA'],
    ['service_number' => '6089216976', 'svc_installation_address' => 'MDLD3823,,LORONG,FAJAR CENTRE,,LAHAD DATU,91100,,MALAYSIA'],
    ['service_number' => '6087504683', 'svc_installation_address' => 'SOME OTHER DIFFERENT ADDRESS, LOT 5, JALAN TEST, 12345, TESTVILLE, MALAYSIA'],
];

// Mirror generate.php's resolveSiteAddress logic for this standalone test
function resolveSiteAddress(array $services): string
{
    $counts = [];
    foreach ($services as $s) {
        $addr = trim($s['svc_installation_address'] ?? '');
        if ($addr === '') continue;
        $counts[$addr] = ($counts[$addr] ?? 0) + 1;
    }
    if (empty($counts)) return '';
    arsort($counts);
    $primary = array_key_first($counts);
    $differing = [];
    foreach ($services as $s) {
        $addr = trim($s['svc_installation_address'] ?? '');
        if ($addr !== '' && $addr !== $primary) {
            $differing[] = $s['service_number'] . ': ' . $addr;
        }
    }
    if (empty($differing)) return $primary;
    return $primary . ' (Note: different address for Service Number ' . implode('; ', $differing) . ')';
}

$fields = [
    'date_generated' => date('d/m/Y'),
    'account_no' => '1040974402',
    'Customer_name' => 'CLINIPATH (MALAYSIA) SDN. BHD.',
    'TM_segment_code' => 'E10',
    'IC/BR_no' => '248187-W',
    'SVC_Installation_Address' => resolveSiteAddress($services),
];

$exporter = new DocxExporter(
    __DIR__ . '/../templates/termination-form.docx',
    __DIR__ . '/../storage/generated'
);

$docx = $exporter->generate($fields, $services);
echo "docx: $docx\n";
$pdf = $exporter->toPdf($docx);
echo "pdf: $pdf\n";
