<?php

namespace App\Exports\AdminStats;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Shared chrome for the five admin-stat spreadsheets, so every export looks
 * like part of one family:
 *   rows 1–2  self-describing metadata (stat name + the exact filters used),
 *   row 4     bold white-on-accent header, frozen and auto-filtered,
 *   data      auto-sized columns, per-class column formats, optional
 *             conditional row highlighting (flagged frameworks, churn risks).
 *
 * Exports never re-query: each concrete class receives the SAME payload the
 * JSON endpoint computed (one shared service per stat) and only reshapes it
 * into rows.
 */
abstract class BaseStatsExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomStartCell, WithEvents, WithHeadings, WithTitle
{
    /** One accent for the whole family (header fill). */
    protected const ACCENT_RGB = '1F4E79';

    /** Soft red fill for rows the admin should look at first. */
    protected const HIGHLIGHT_RGB = 'FADBD8';

    protected const HEADER_ROW = 4;

    private ?array $memoRows = null;

    public function __construct(
        protected readonly array $payload,
        protected readonly array $filtersUsed,
    ) {}

    /** Human sheet/stat name, e.g. "Framework Fairness". */
    abstract protected function sheetName(): string;

    /** @return array<int, array<int, mixed>> data rows (no heading row). */
    abstract protected function buildRows(): array;

    /** Whether a data row deserves the attention highlight. */
    protected function highlightRow(array $row): bool
    {
        return false;
    }

    public function array(): array
    {
        return $this->memoRows ??= $this->buildRows();
    }

    public function columnFormats(): array
    {
        return [];
    }

    public function startCell(): string
    {
        return 'A' . self::HEADER_ROW;
    }

    public function title(): string
    {
        return mb_substr($this->sheetName(), 0, 31);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->decorate($event->sheet->getDelegate()),
        ];
    }

    private function decorate(Worksheet $sheet): void
    {
        $rows = $this->array();
        $lastCol = Coordinate::stringFromColumnIndex(max(1, count($this->headings())));
        $headerRow = self::HEADER_ROW;
        $firstDataRow = $headerRow + 1;
        $lastDataRow = $headerRow + count($rows);

        // ── metadata block (self-describing out of context) ─────────────────
        $sheet->setCellValue('A1', $this->sheetName() . ' — Jadal Admin Statistics');
        $sheet->setCellValue('A2', 'Generated: ' . now()->format('Y-m-d H:i') . '   |   Filters: ' . $this->filtersLine());
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('595959');

        // ── header row: bold white on the family accent ──────────────────────
        $headerRange = "A{$headerRow}:{$lastCol}{$headerRow}";
        $headerStyle = $sheet->getStyle($headerRange);
        $headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::ACCENT_RGB);

        // ── freeze everything above the data + filter on the header ─────────
        $sheet->freezePane('A' . $firstDataRow);
        $sheet->setAutoFilter($lastDataRow >= $firstDataRow
            ? "A{$headerRow}:{$lastCol}{$lastDataRow}"
            : $headerRange);

        // ── conditional attention highlighting ───────────────────────────────
        foreach ($rows as $i => $row) {
            if ($this->highlightRow($row)) {
                $rowIdx = $firstDataRow + $i;
                $sheet->getStyle("A{$rowIdx}:{$lastCol}{$rowIdx}")
                    ->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB(self::HIGHLIGHT_RGB);
            }
        }
    }

    private function filtersLine(): string
    {
        $parts = [];
        foreach ($this->filtersUsed as $k => $v) {
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            $parts[] = $k . '=' . (is_array($v) ? implode('|', $v) : (string) $v);
        }

        return empty($parts) ? 'none (full history)' : implode(', ', $parts);
    }
}
