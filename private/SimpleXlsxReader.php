<?php
/**
 * SimpleXlsxReader — Zero-dependency XLSX parser using PHP's ZipArchive + SimpleXML.
 * Reads the first worksheet of an .xlsx file and returns rows as arrays.
 *
 * Usage:
 *   $reader = new SimpleXlsxReader();
 *   $reader->open('/path/to/file.xlsx');
 *   $rows   = $reader->readAsNamedRows();   // array of ['column_name' => value]
 *   $reader->close();
 */
class SimpleXlsxReader {

    private ?ZipArchive $zip   = null;
    private array $sharedStrings = [];
    private array $xfNumFmtIds   = [];  // xf index → numFmtId
    private array $customFmts    = [];  // numFmtId → formatCode

    // Excel built-in date format IDs (ISO 8601 subset Excel treats as dates)
    private const DATE_IDS = [14,15,16,17,18,19,20,21,22,45,46,47];

    // ── Public API ────────────────────────────────────────────────────────────

    public function open(string $path): bool {
        $this->zip = new ZipArchive();
        return $this->zip->open($path) === true;
    }

    public function close(): void {
        if ($this->zip) { $this->zip->close(); $this->zip = null; }
    }

    /**
     * Read the first sheet, auto-detect the header row (the first row containing
     * 'compound_name' or 'cas_number'), and return subsequent non-empty rows as
     * associative arrays keyed by normalised column name.
     *
     * Header normalisation mirrors importCompoundsFromCSV():
     *   - Strips UTF-8 BOM
     *   - Strips trailing " *"
     *   - Strips trailing parenthetical " (auto)", " (internal)", " (YYYY-MM-DD)", etc.
     *   - Lower-cases and trims
     *
     * @return array[]   Each element is ['column_name' => 'cell_value', ...]
     */
    public function readAsNamedRows(): array {
        $this->parseSharedStrings();
        $this->parseStyles();
        $rawRows = $this->parseSheet();

        if (empty($rawRows)) return [];

        // Find header row — first row that contains a recognisable field name
        $headerIdx = 0;
        foreach ($rawRows as $i => $row) {
            $flat = strtolower(implode('|', $row));
            if (str_contains($flat, 'compound_name') || str_contains($flat, 'cas_number')) {
                $headerIdx = $i;
                break;
            }
        }

        $rawHeaders = $rawRows[$headerIdx];
        $headers    = array_map([$this, 'normaliseHeader'], $rawHeaders);

        $result = [];
        for ($i = $headerIdx + 1; $i < count($rawRows); $i++) {
            $row = $rawRows[$i];
            // Skip fully-empty rows
            if (empty(array_filter($row, static fn($v) => trim((string)$v) !== ''))) continue;

            $named = [];
            foreach ($headers as $col => $name) {
                $named[$name] = trim((string)($row[$col] ?? ''));
            }
            $result[] = $named;
        }
        return $result;
    }

    // ── Parsing internals ────────────────────────────────────────────────────

    private function parseSharedStrings(): void {
        $xml = $this->entry('xl/sharedStrings.xml');
        if ($xml === null) return;

        $doc = @simplexml_load_string($xml);
        if (!$doc) return;

        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $doc->registerXPathNamespace('x', $ns);

        foreach ($doc->xpath('//x:si') as $si) {
            $parts = [];
            foreach ($si->xpath('.//x:t') as $t) {
                $attrs = $t->attributes('xml', true);
                $parts[] = (isset($attrs['space']) && (string)$attrs['space'] === 'preserve')
                    ? (string)$t
                    : trim((string)$t);
            }
            $this->sharedStrings[] = implode('', $parts);
        }
    }

    private function parseStyles(): void {
        $xml = $this->entry('xl/styles.xml');
        if ($xml === null) return;

        $doc = @simplexml_load_string($xml);
        if (!$doc) return;

        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $doc->registerXPathNamespace('x', $ns);

        foreach ($doc->xpath('//x:numFmt') as $fmt) {
            $this->customFmts[(int)(string)$fmt['numFmtId']] = (string)$fmt['formatCode'];
        }
        foreach ($doc->xpath('//x:cellXfs/x:xf') as $xf) {
            $this->xfNumFmtIds[] = (int)(string)($xf['numFmtId'] ?? 0);
        }
    }

    private function parseSheet(): array {
        // Discover actual sheet1 path from workbook relationships
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wbRels = $this->entry('xl/_rels/workbook.xml.rels');
        if ($wbRels) {
            $doc = @simplexml_load_string($wbRels);
            if ($doc) {
                $relNs = 'http://schemas.openxmlformats.org/package/2006/relationships';
                foreach ($doc->children($relNs) as $rel) {
                    if (str_contains((string)$rel['Type'], '/worksheet')) {
                        $target    = ltrim((string)$rel['Target'], '/');
                        $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                        break;
                    }
                }
            }
        }

        $xml = $this->entry($sheetPath);
        if ($xml === null) return [];

        $doc = @simplexml_load_string($xml);
        if (!$doc) return [];

        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $doc->registerXPathNamespace('x', $ns);

        $rows = [];
        foreach ($doc->xpath('//x:row') as $xmlRow) {
            $rowData = [];
            $maxCol  = -1;
            foreach ($xmlRow->children($ns) as $cell) {
                if ($cell->getName() !== 'c') continue;
                $colIdx          = $this->refToColIdx((string)($cell['r'] ?? 'A1'));
                $val             = $this->cellValue($cell);
                $rowData[$colIdx] = $val;
                if ($colIdx > $maxCol) $maxCol = $colIdx;
            }
            if ($maxCol < 0) continue;
            $filled = [];
            for ($c = 0; $c <= $maxCol; $c++) { $filled[] = $rowData[$c] ?? ''; }
            $rows[] = $filled;
        }
        return $rows;
    }

    private function cellValue(\SimpleXMLElement $cell): string {
        $type = (string)($cell['t'] ?? '');
        $raw  = trim((string)($cell->v ?? ''));

        if ($type === 's')         return $this->sharedStrings[(int)$raw] ?? '';
        if ($type === 'inlineStr') return trim((string)($cell->is->t ?? ''));
        if ($type === 'b')         return $raw === '1' ? 'TRUE' : 'FALSE';
        if ($type === 'str')       return $raw; // formula result (string)

        // Numeric — check if styled as a date
        if ($raw !== '') {
            $xfIdx    = (int)($cell['s'] ?? 0);
            $numFmtId = $this->xfNumFmtIds[$xfIdx] ?? 0;
            $fmtCode  = $this->customFmts[$numFmtId] ?? '';
            if (in_array($numFmtId, self::DATE_IDS, true) || $this->looksLikeDateFmt($fmtCode)) {
                return $this->serialToDate((float)$raw);
            }
        }
        return $raw;
    }

    private function looksLikeDateFmt(string $fmt): bool {
        if ($fmt === '') return false;
        return preg_match('/[ymd]/i', $fmt) === 1 && preg_match('/[#0]/', $fmt) === 0;
    }

    private function serialToDate(float $serial): string {
        // Correct for Lotus 1-2-3 bug (Excel treats 1900-02-29 as real)
        if ($serial >= 60) $serial--;
        return date('Y-m-d', (int)(($serial - 25569) * 86400));
    }

    /** Convert "B3" → 1 (0-based column index) */
    private function refToColIdx(string $ref): int {
        preg_match('/^([A-Za-z]+)/', $ref, $m);
        $letters = strtoupper($m[1] ?? 'A');
        $idx = 0;
        foreach (str_split($letters) as $ch) { $idx = $idx * 26 + (ord($ch) - 64); }
        return $idx - 1;
    }

    private function normaliseHeader(string $h): string {
        $h = ltrim(trim($h), "\xEF\xBB\xBF"); // strip BOM
        $h = rtrim($h, ' *');                  // "compound_name *" → "compound_name"
        $h = preg_replace('/\s*\([^)]*\)\s*$/', '', $h); // strip " (auto)", " (YYYY-MM-DD)", etc.
        return strtolower(trim($h));
    }

    private function entry(string $name): ?string {
        if (!$this->zip) return null;
        $data = $this->zip->getFromName($name);
        return $data !== false ? $data : null;
    }
}
