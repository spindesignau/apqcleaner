<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Minimal read-only .xlsx reader (no external dependency).
 * Returns cached cell values — formulas are read as the value Excel last calculated.
 */
class XlsxReader
{
    private ZipArchive $zip;

    /** @var array<int,string> */
    private array $sharedStrings = [];

    /** @var array<string,string> sheet name => zip path */
    private array $sheets = [];

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive;
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException("Cannot open workbook: {$path}");
        }
        $this->loadSharedStrings();
        $this->loadSheetIndex();
    }

    /** @return string[] */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    /**
     * @return array<int,array<string,mixed>> rows keyed by row number, cells keyed by column letter
     */
    public function rows(string $sheet): array
    {
        $path = $this->sheets[$sheet] ?? throw new RuntimeException("Sheet not found: {$sheet}");
        $xml = $this->xml($path);
        $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];
        foreach ($xml->xpath('//m:sheetData/m:row') as $row) {
            $r = (int) $row['r'];
            foreach ($row->c as $c) {
                $value = $this->cellValue($c);
                if ($value === null || $value === '') {
                    continue;
                }
                $col = preg_replace('/\d+/', '', (string) $c['r']);
                $rows[$r][$col] = $value;
            }
        }

        return $rows;
    }

    private function cellValue(SimpleXMLElement $c): mixed
    {
        $type = (string) $c['t'];

        return match ($type) {
            's' => $this->sharedStrings[(int) $c->v] ?? null,
            'inlineStr' => $this->text($c->is),
            'b' => (bool) (int) $c->v,
            'str', 'e' => isset($c->v) ? (string) $c->v : null,
            default => isset($c->v) && $c->v !== '' ? (is_numeric((string) $c->v) ? (float) $c->v : (string) $c->v) : null,
        };
    }

    private function loadSharedStrings(): void
    {
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return;
        }
        foreach ($this->xml('xl/sharedStrings.xml')->si as $si) {
            $this->sharedStrings[] = $this->text($si);
        }
    }

    private function loadSheetIndex(): void
    {
        $rels = [];
        foreach ($this->xml('xl/_rels/workbook.xml.rels')->Relationship as $rel) {
            $target = ltrim((string) $rel['Target'], '/');
            $rels[(string) $rel['Id']] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }
        $wb = $this->xml('xl/workbook.xml');
        foreach ($wb->sheets->sheet as $s) {
            $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $this->sheets[(string) $s['name']] = $rels[$rid];
        }
    }

    private function text(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $out = '';
        foreach ($node->r as $run) {
            $out .= (string) $run->t;
        }

        return $out;
    }

    private function xml(string $path): SimpleXMLElement
    {
        $content = $this->zip->getFromName($path);
        if ($content === false) {
            throw new RuntimeException("Missing part in workbook: {$path}");
        }

        return new SimpleXMLElement($content, LIBXML_NONET | LIBXML_COMPACT);
    }
}
