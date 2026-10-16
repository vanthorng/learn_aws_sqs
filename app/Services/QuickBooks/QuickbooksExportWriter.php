<?php

namespace App\Services\QuickBooks;

use RuntimeException;
use ZipArchive;

class QuickbooksExportWriter
{
    /** @param list<array<string, scalar|null>> $invoices @param list<array<string, scalar|null>> $lines */
    public function write(array $invoices, array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qbo-export-');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to create export workbook.');

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Invoices" sheetId="1" r:id="rId1"/><sheet name="Invoice lines" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet(array_keys($invoices[0] ?? ['Invoice ID' => null, 'Doc Number' => null, 'Customer' => null, 'Transaction Date' => null, 'Due Date' => null, 'Total' => null, 'Balance' => null, 'Status' => null]), $invoices));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->sheet(array_keys($lines[0] ?? ['Invoice ID' => null, 'Doc Number' => null, 'Line Number' => null, 'Description' => null, 'Item' => null, 'Quantity' => null, 'Unit Price' => null, 'Amount' => null]), $lines));
        $zip->close();

        return $path;
    }

    /** @param list<string> $headers @param list<array<string, scalar|null>> $rows */
    private function sheet(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_merge([$headers], array_map(fn (array $row) => array_map(fn (string $key) => $row[$key] ?? null, $headers), $rows)) as $rowNumber => $row) {
            $xml .= '<row r="'.($rowNumber + 1).'">';
            foreach ($row as $column => $value) {
                $reference = $this->columnName($column + 1).($rowNumber + 1);
                $xml .= '<c r="'.$reference.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $xml .= '</row>';
        }
        return $xml.'</sheetData></worksheet>';
    }

    private function columnName(int $column): string
    {
        $name = '';
        while ($column > 0) { $column--; $name = chr(65 + ($column % 26)).$name; $column = intdiv($column, 26); }
        return $name;
    }
}
