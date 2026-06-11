<?php
/**
 * SupplierTemplateGenerator — Creates a coloured XLSX supplier import template.
 * Zero external dependencies: uses PHP's built-in ZipArchive.
 *
 * Column sections:
 *   A (green)      — Required: compound identity
 *   B (yellow)     — Commercial: availability, purity, quantities
 *   C (blue)       — Classification: product type, regulatory
 *   D (pink)       — Notes
 *   E (light gray) — Auto-fill from PubChem — LEAVE BLANK
 *   F (dark gray)  — ABChem internal — DO NOT EDIT
 *
 * Usage:
 *   $gen     = new SupplierTemplateGenerator();
 *   $bytes   = $gen->generate('Acme Pharma');
 *   header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
 *   header('Content-Disposition: attachment; filename="supplier_import_template.xlsx"');
 *   echo $bytes;
 */
class SupplierTemplateGenerator {

    // Blank data rows pre-created for supplier to fill
    private const DATA_ROWS = 200;

    // Section definitions: section_key → [banner_fill_xf, cell_xf, font_xf]
    // xf indices defined in xmlStyles()
    private const XF = [
        'required'   => 1,
        'commercial' => 2,
        'class'      => 3,
        'notes'      => 4,
        'autofill'   => 5,
        'internal'   => 6,
        'banner'     => 7,
    ];

    /**
     * Column definitions:
     * [label (shown in Excel), section_key, col_width, dropdown_csv_values|null]
     *
     * Labels ending with " *" = required.
     * Labels ending with " (auto)" = auto-filled from PubChem — leave blank.
     * Labels ending with " (internal)" = ABChem internal — do not edit.
     * The reader strips these suffixes automatically.
     */
    private const COLS = [
        // ── Section A: Required ────────────────────────────────────────────
        ['compound_name *',              'required',   30, null],
        ['cas_number',                   'required',   16, null],
        ['supplier_catalog_number',      'required',   22, null],
        // ── Section B: Commercial ─────────────────────────────────────────
        ['availability',                 'commercial', 16, 'In Stock,On Request,Backorder,Discontinued'],
        ['stock_status',                 'commercial', 14, 'in_stock,low_stock,backordered,discontinued'],
        ['min_order_qty',                'commercial', 14, null],
        ['unit',                         'commercial', 10, 'mg,g,kg,ml,L,vial,ampoule,tablet,capsule,lot'],
        ['purity',                       'commercial', 12, null],
        ['purity_by_method',             'commercial', 16, 'HPLC,NMR,GC,Titration,UV,Other'],
        ['lead_time',                    'commercial', 14, null],
        ['lot_number',                   'commercial', 14, null],
        ['manufacture_date (YYYY-MM-DD)','commercial', 22, null],
        ['expiry_date (YYYY-MM-DD)',      'commercial', 22, null],
        ['storage_condition',            'commercial', 22, 'Room Temperature,2-8C,-20C,-80C,Protected from light,Refrigerated'],
        // ── Section C: Classification ──────────────────────────────────────
        ['product_type',                 'class',      20, 'API,Impurity,Metabolite,Reference Standard,Reagent,Intermediate,Other'],
        ['parent_drug',                  'class',      22, null],
        ['therapeutic_category',         'class',      24, null],
        ['hazard_class',                 'class',      16, null],
        ['regulatory_ref',               'class',      22, null],
        // ── Section D: Notes ───────────────────────────────────────────────
        ['supplier_notes',               'notes',      32, null],
        // ── Section E: Auto-fill from PubChem (leave blank) ───────────────
        ['iupac_name (auto)',            'autofill',   32, null],
        ['molecular_formula (auto)',     'autofill',   20, null],
        ['molecular_weight (auto)',      'autofill',   18, null],
        ['smiles (auto)',                'autofill',   30, null],
        ['inchi (auto)',                 'autofill',   30, null],
        ['inchi_key (auto)',             'autofill',   30, null],
        ['synonyms (auto)',              'autofill',   30, null],
        ['pubchem_cid (auto)',           'autofill',   18, null],
        // ── Section F: ABChem Internal (do not edit) ──────────────────────
        ['ab_catalog_number (internal)', 'internal',   24, null],
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * @param string $supplierName  Pre-filled in the banner row.
     * @return string  Raw XLSX binary bytes.
     */
    public function generate(string $supplierName = ''): string {
        [$strings, $map] = $this->buildStringTable($supplierName);

        $tmpFile = tempnam(sys_get_temp_dir(), 'abchem_tpl_') . '.xlsx';
        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create XLSX temp file: $tmpFile");
        }

        $zip->addFromString('[Content_Types].xml',        $this->xmlContentTypes());
        $zip->addFromString('_rels/.rels',                 $this->xmlRootRels());
        $zip->addFromString('xl/workbook.xml',             $this->xmlWorkbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels',  $this->xmlWorkbookRels());
        $zip->addFromString('xl/styles.xml',               $this->xmlStyles());
        $zip->addFromString('xl/sharedStrings.xml',        $this->xmlSharedStrings($strings));
        $zip->addFromString('xl/worksheets/sheet1.xml',    $this->xmlSheet($map, $supplierName));
        $zip->close();

        $bytes = file_get_contents($tmpFile);
        @unlink($tmpFile);
        return $bytes ?: '';
    }

    // ── String table ──────────────────────────────────────────────────────────

    private function buildStringTable(string $supplierName): array {
        $strings = [];
        $map     = [];
        $idx     = 0;
        $add     = static function (string $s) use (&$strings, &$map, &$idx): int {
            if (!isset($map[$s])) { $map[$s] = $idx++; $strings[] = $s; }
            return $map[$s];
        };

        $add('AB CHEM — Supplier Import Template');
        $add($supplierName ? "Supplier: $supplierName" : '');
        $add('GREEN = Required  |  YELLOW = Commercial  |  BLUE = Classification  |  PINK = Notes  |  GRAY = Auto-fill from PubChem — leave blank  |  DARK GRAY = ABChem internal — do not edit');
        foreach (self::COLS as [$label]) { $add($label); }

        return [$strings, $map];
    }

    // ── XML helpers ───────────────────────────────────────────────────────────

    /** Convert 0-based column index to Excel column letters (A, B, …, Z, AA, …) */
    private function col(int $idx): string {
        $letters = '';
        for ($n = $idx + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26) . $letters;
        }
        return $letters;
    }

    private function sCell(string $ref, int $strIdx, int $xf): string {
        return "<c r=\"{$ref}\" t=\"s\" s=\"{$xf}\"><v>{$strIdx}</v></c>";
    }

    private function eCell(string $ref, int $xf): string {
        return "<c r=\"{$ref}\" s=\"{$xf}\"/>";
    }

    // ── Sheet XML ─────────────────────────────────────────────────────────────

    private function xmlSheet(array $map, string $supplierName): string {
        $ncols   = count(self::COLS);
        $lastCol = $this->col($ncols - 1);

        // ── Column widths ──
        $colsXml = '';
        foreach (self::COLS as $i => [, , $w]) {
            $n       = $i + 1;
            $colsXml .= "<col min=\"{$n}\" max=\"{$n}\" width=\"{$w}\" customWidth=\"1\"/>";
        }

        // ── Row 1: Banner (merged A1:lastCol1) ──
        $bannerStr = 'AB CHEM — Supplier Import Template' . ($supplierName ? " — Supplier: $supplierName" : '');
        // Find closest match in map
        $bannerIdx = $map[$bannerStr] ?? $map['AB CHEM — Supplier Import Template'];
        $row1Cells = $this->sCell('A1', $bannerIdx, self::XF['banner']);
        for ($c = 1; $c < $ncols; $c++) {
            $row1Cells .= $this->eCell($this->col($c) . '1', self::XF['banner']);
        }

        // ── Row 2: Colour legend ──
        $legendIdx = $map['GREEN = Required  |  YELLOW = Commercial  |  BLUE = Classification  |  PINK = Notes  |  GRAY = Auto-fill from PubChem — leave blank  |  DARK GRAY = ABChem internal — do not edit'];
        $row2Cells = $this->sCell('A2', $legendIdx, 0);
        for ($c = 1; $c < $ncols; $c++) {
            $row2Cells .= $this->eCell($this->col($c) . '2', 0);
        }

        // ── Row 3: Column headers ──
        $row3Cells = '';
        foreach (self::COLS as $i => [$label, $section]) {
            $row3Cells .= $this->sCell($this->col($i) . '3', $map[$label], self::XF[$section]);
        }

        // ── Rows 4…(3+DATA_ROWS): blank coloured data rows ──
        $dataRowsXml = '';
        for ($row = 4; $row <= 3 + self::DATA_ROWS; $row++) {
            $cells = '';
            foreach (self::COLS as $i => [, $section]) {
                $cells .= $this->eCell($this->col($i) . $row, self::XF[$section]);
            }
            $dataRowsXml .= "<row r=\"{$row}\">{$cells}</row>\n    ";
        }

        // ── Merge: A1:lastCol1 (banner) and A2:lastCol2 (legend) ──
        $mergeXml = "<mergeCells count=\"2\">
      <mergeCell ref=\"A1:{$lastCol}1\"/>
      <mergeCell ref=\"A2:{$lastCol}2\"/>
    </mergeCells>";

        // ── Data validations (dropdowns) ──
        $dvItems = '';
        $dvCount = 0;
        $lastDataRow = 3 + self::DATA_ROWS;
        foreach (self::COLS as $i => [, , , $dv]) {
            if ($dv === null) continue;
            $c   = $this->col($i);
            $dvItems .= "<dataValidation type=\"list\" allowBlank=\"1\" showDropDown=\"0\" sqref=\"{$c}4:{$c}{$lastDataRow}\"><formula1>\"{$dv}\"</formula1></dataValidation>";
            $dvCount++;
        }
        $dvXml = $dvCount ? "<dataValidations count=\"{$dvCount}\">{$dvItems}</dataValidations>" : '';

        // ── Freeze panes at row 4 (rows 1–3 locked) ──
        $viewXml = '<sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  {$viewXml}
  <sheetFormatPr defaultRowHeight="15" customHeight="0"/>
  <cols>{$colsXml}</cols>
  <sheetData>
    <row r="1" ht="18" customHeight="1">{$row1Cells}</row>
    <row r="2" ht="30" customHeight="1">{$row2Cells}</row>
    <row r="3" ht="16" customHeight="1">{$row3Cells}</row>
    {$dataRowsXml}
  </sheetData>
  {$mergeXml}
  {$dvXml}
</worksheet>
XML;
    }

    // ── Boilerplate XML ───────────────────────────────────────────────────────

    private function xmlContentTypes(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"           ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml"  ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/sharedStrings.xml"      ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
  <Override PartName="/xl/styles.xml"             ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';
    }

    private function xmlRootRels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    }

    private function xmlWorkbook(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Supplier Data" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';
    }

    private function xmlWorkbookRels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"    Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"        Target="styles.xml"/>
</Relationships>';
    }

    private function xmlSharedStrings(array $strings): string {
        $count = count($strings);
        $items = implode("\n  ", array_map(
            static fn($s) => '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1, 'UTF-8') . '</t></si>',
            $strings
        ));
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="{$count}" uniqueCount="{$count}">
  {$items}
</sst>
XML;
    }

    private function xmlStyles(): string {
        /*
         * xf (cellXfs) indices:
         *  0 — default (no style)
         *  1 — Section A header: bold, green fill (#C6EFCE)
         *  2 — Section B header: bold, yellow fill (#FFEB9C)
         *  3 — Section C header: bold, blue fill (#BDD7EE)
         *  4 — Section D header: bold, pink fill (#FFCCFF)
         *  5 — Section E header: bold italic gray text, light-gray fill (#ECECEC)
         *  6 — Section F header: bold italic gray text, medium-gray fill (#D9D9D9)
         *  7 — Banner row: white bold 11pt on dark green (#375623)
         *
         * Note: fills 0 (none) and 1 (gray125) are required by the OOXML spec.
         */
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="4">
    <font><sz val="10"/><name val="Calibri"/><family val="2"/></font>
    <font><b/><sz val="10"/><name val="Calibri"/><family val="2"/></font>
    <font><b/><i/><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/><family val="2"/></font>
    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>
  </fonts>
  <fills count="9">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFC6EFCE"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFEB9C"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFBDD7EE"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFCCFF"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFECECEC"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF375623"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="1">
    <border><left/><right/><top/><bottom/><diagonal/></border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="8">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="0" fontId="1" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="0" fontId="1" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="0" fontId="1" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="0" fontId="2" fillId="6" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="0" fontId="2" fillId="7" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="0" fontId="3" fillId="8" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
  </cellXfs>
</styleSheet>';
    }
}
