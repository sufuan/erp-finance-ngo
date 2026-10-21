<?php

namespace App\Services;

use ZipArchive;

/**
 * Minimal single-sheet XLSX reader/writer (Step 9A) built on ZipArchive +
 * SimpleXML — deliberately dependency-free so the member migration works
 * without pulling a spreadsheet library over the wire. Reads shared-string,
 * inline-string, boolean and numeric cells (dates arrive as Excel serials
 * and are converted by the caller); writes inline-string/number sheets
 * that Excel, LibreOffice and PhpSpreadsheet all open.
 */
class XlsxService
{
    /** @return array<int, array<int, string>> Rows of column-indexed string values. */
    public function read(string $path): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            return $this->readCsv($path);
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            // Uploaded temp files lose their extension — fall back to CSV.
            return $this->readCsv($path);
        }
        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $xml = simplexml_load_string($sharedXml);
            if ($xml !== false) {
                foreach ($xml->children() as $si) {
                    $text = '';
                    foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                        $text .= (string) $t;
                    }
                    $shared[] = $text;
                }
            }
        }
        $sheetXml = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string) $name)) {
                $sheetXml = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();
        if ($sheetXml === false) {
            return [];
        }
        $xml = simplexml_load_string($sheetXml);
        if ($xml === false) {
            return [];
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $out = [];
            foreach ($row->c as $cell) {
                $col = $this->columnIndex((string) $cell['r']);
                if ($col === null) {
                    $col = count($out);
                }
                $type = (string) $cell['t'];
                if ($type === 's') {
                    $value = $shared[(int) $cell->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = isset($cell->is) ? (string) $cell->is->t : '';
                } elseif ($type === 'b') {
                    $value = ((string) $cell->v) === '1' ? 'TRUE' : 'FALSE';
                } else {
                    $value = isset($cell->v) ? (string) $cell->v : '';
                }
                $out[$col] = $value;
            }
            ksort($out);
            $rows[] = $out;
        }

        return $rows;
    }

    /** @param array<int, array<int, mixed>> $rows */
    public function write(string $path, array $rows): void
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_values($rows) as $r => $cols) {
            $sheet .= '<row r="'.($r + 1).'">';
            foreach ($cols as $c => $value) {
                $ref = $this->columnName((int) $c).($r + 1);
                if (is_int($value) || is_float($value)) {
                    $sheet .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } else {
                    $sheet .= '<c r="'.$ref.'" t="inlineStr"><is><t>'
                        .htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                        .'</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
    }

    /** Excel serial day number → Y-m-d (valid for serials past the 1900 leap bug). */
    public static function serialToDate(float $serial): string
    {
        $unix = (int) round(($serial - 25569) * 86400);

        return gmdate('Y-m-d', $unix);
    }

    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        while (($line = fgetcsv($handle)) !== false) {
            $rows[] = array_map(fn ($v) => (string) ($v ?? ''), $line);
        }
        fclose($handle);

        return $rows;
    }

    private function columnIndex(string $ref): ?int
    {
        if (! preg_match('/^([A-Z]+)\d+$/', $ref, $m)) {
            return null;
        }
        $col = 0;
        foreach (str_split($m[1]) as $ch) {
            $col = $col * 26 + (ord($ch) - 64);
        }

        return $col - 1;
    }

    private function columnName(int $index): string
    {
        $name = '';
        $n = $index + 1;
        while ($n > 0) {
            $n--;
            $name = chr(65 + ($n % 26)).$name;
            $n = intdiv($n, 26);
        }

        return $name;
    }
}
