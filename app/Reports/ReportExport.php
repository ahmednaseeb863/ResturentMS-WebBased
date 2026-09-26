<?php

namespace App\Reports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** A report as an Excel sheet: title, branch and period, the table and its totals row. */
class ReportExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    private const HEADER_ROW = 4;

    /** @param  array{rows: list<array>, totals: ?array}  $result */
    public function __construct(private Report $report, private ReportFilters $filters, private array $result) {}

    public function title(): string
    {
        return substr($this->report->title(), 0, 31);
    }

    public function headings(): array
    {
        return [
            [$this->report->title()],
            [$this->filters->branchLabel().' · '.($this->report->usesDates() ? $this->filters->periodLabel() : 'as of '.now(setting('general.timezone', $this->filters->mainBranchId()))->format('j M Y H:i'))],
            [],
            array_column($this->report->columns(), 'label'),
        ];
    }

    public function array(): array
    {
        $keys = array_column($this->report->columns(), 'key');
        $line = fn (array $row) => array_map(fn ($k) => $row[$k] ?? null, $keys);

        $rows = array_map($line, $this->result['rows']);
        if ($this->result['totals']) {
            $rows[] = $line($this->result['totals']);
        }

        return $rows;
    }

    public function columnFormats(): array
    {
        $formats = [];
        foreach (array_values($this->report->columns()) as $i => $column) {
            $format = match ($column['type']) {
                'money' => '#,##0.00',
                'qty' => '#,##0.###',
                'int' => '#,##0',
                'percent' => '0.0"%"',
                default => null,
            };
            if ($format) {
                $formats[Coordinate::stringFromColumnIndex($i + 1)] = $format;
            }
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [1 => ['font' => ['bold' => true, 'size' => 14]], self::HEADER_ROW => ['font' => ['bold' => true]]];
        if ($this->result['totals']) {
            $styles[self::HEADER_ROW + count($this->result['rows']) + 1] = ['font' => ['bold' => true]];
        }

        return $styles;
    }
}
