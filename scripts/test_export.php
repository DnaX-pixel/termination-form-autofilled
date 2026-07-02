<?php
require __DIR__ . '/../app/Services/DocxExporter.php';

$exporter = new DocxExporter(
    __DIR__ . '/../templates/termination-form.docx',
    __DIR__ . '/../storage/generated'
);

$fields = [
    'date_generated' => date('d/m/Y'),
    'service_number' => '6089889331',
    'account_no' => '1040974402',
    'Customer_name' => 'CLINIPATH (MALAYSIA) SDN. BHD.',
    'TM_segment_code' => 'E10',
    'IC/BR_no' => '248187-W',
    'SVC_Installation_Address' => 'MDLD3823,,LORONG,FAJAR CENTRE,,LAHAD DATU,91100,,MALAYSIA',
];

$docx = $exporter->generate($fields);
echo "Generated docx: $docx\n";

$pdf = $exporter->toPdf($docx);
echo "Generated pdf: $pdf\n";
