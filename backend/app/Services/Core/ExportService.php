<?php

namespace App\Services\Core;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns any tabular report into an Excel or PDF download.
 *
 * Columns: ['key' => 'Label'] or ['key' => ['label' => 'Label', 'type' => 'money|number|date|text']]
 */
class ExportService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function download(string $format, string $title, array $columns, iterable $rows, array $totals = [], array $filters = []): Response
    {
        $columns = $this->normalizeColumns($columns);
        $filename = Str::slug($title).'-'.now()->format('Ymd-His');

        return $format === 'pdf'
            ? $this->pdf($filename, $title, $columns, $rows, $totals, $filters)
            : $this->xlsx($filename, $title, $columns, $rows, $totals, $filters);
    }

    private function xlsx(string $filename, string $title, array $columns, iterable $rows, array $totals, array $filters): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(Str::limit(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $title), 28, ''));
        $lastCol = Coordinate::stringFromColumnIndex(count($columns));

        $sheet->setCellValueExplicit('A1', (string) $this->settings->get('business_name'), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A2', $title, DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A3', 'Generated: '.now()->format('d/m/Y H:i').($filters ? '  |  '.$this->filterText($filters) : ''), DataType::TYPE_STRING);
        $sheet->getStyle('A1:A2')->getFont()->setBold(true)->setSize(13);

        $rowIndex = 5;
        $col = 1;
        foreach ($columns as $column) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($col++).$rowIndex, $column['label'], DataType::TYPE_STRING);
        }
        $headerRange = "A{$rowIndex}:{$lastCol}{$rowIndex}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');

        foreach ($rows as $row) {
            $row = $this->toArray($row);
            $rowIndex++;
            $col = 1;
            foreach ($columns as $key => $column) {
                $value = data_get($row, $key);
                $cell = Coordinate::stringFromColumnIndex($col++).$rowIndex;
                if (in_array($column['type'], ['money', 'number'], true) && is_numeric($value)) {
                    $sheet->setCellValue($cell, (float) $value);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($column['type'] === 'money' ? '#,##0.00' : '#,##0.###');
                } else {
                    // Explicit string type: user text starting with = + - @ is never treated as a formula.
                    $sheet->setCellValueExplicit($cell, (string) ($this->format($value, $column['type'])), DataType::TYPE_STRING);
                }
            }
        }

        if ($totals) {
            $rowIndex++;
            $col = 1;
            foreach ($columns as $key => $column) {
                $cell = Coordinate::stringFromColumnIndex($col++).$rowIndex;
                if ($col === 2) {
                    $sheet->setCellValue($cell, 'TOTAL');
                }
                if (array_key_exists($key, $totals)) {
                    $sheet->setCellValue($cell, (float) $totals[$key]);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
                }
            }
            $sheet->getStyle("A{$rowIndex}:{$lastCol}{$rowIndex}")->getFont()->setBold(true);
        }

        foreach (range(1, count($columns)) as $i) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        return new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}.xlsx\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    private function pdf(string $filename, string $title, array $columns, iterable $rows, array $totals, array $filters): Response
    {
        $formatted = [];
        foreach ($rows as $row) {
            $row = $this->toArray($row);
            $line = [];
            foreach ($columns as $key => $column) {
                $line[$key] = $this->format(data_get($row, $key), $column['type']);
            }
            $formatted[] = $line;
        }

        $pdf = Pdf::loadView('pdf.report', [
            'business' => $this->settings->all(),
            'title' => $title,
            'columns' => $columns,
            'rows' => $formatted,
            'totals' => array_map(fn ($v) => number_format((float) $v, 2), $totals),
            'filters' => $this->filterText($filters),
        ])->setPaper('a4', count($columns) > 7 ? 'landscape' : 'portrait');

        return $pdf->download("{$filename}.pdf");
    }

    /** Models serialise relations in snake_case (fromBranch -> from_branch), matching the column keys. */
    private function toArray(mixed $row): array
    {
        return match (true) {
            $row instanceof Arrayable => $row->toArray(),
            is_object($row) => (array) $row,
            default => (array) $row,
        };
    }

    private function normalizeColumns(array $columns): array
    {
        $normalized = [];
        foreach ($columns as $key => $column) {
            $normalized[$key] = is_array($column)
                ? ['label' => $column['label'], 'type' => $column['type'] ?? 'text']
                : ['label' => $column, 'type' => 'text'];
        }

        return $normalized;
    }

    private function format(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'money' => is_numeric($value) ? number_format((float) $value, 2) : (string) $value,
            'number' => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 3), '0'), '.') : (string) $value,
            'date' => $value instanceof \DateTimeInterface ? $value->format('d/m/Y') : (strtotime((string) $value) ? date('d/m/Y', strtotime((string) $value)) : (string) $value),
            'datetime' => $value instanceof \DateTimeInterface ? $value->format('d/m/Y H:i') : (strtotime((string) $value) ? date('d/m/Y H:i', strtotime((string) $value)) : (string) $value),
            'bool' => $value ? 'Yes' : 'No',
            default => is_array($value) ? json_encode($value) : (string) $value,
        };
    }

    private function filterText(array $filters): string
    {
        return collect($filters)
            ->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])
            ->map(fn ($v, $k) => Str::headline($k).': '.(is_array($v) ? implode(', ', $v) : $v))
            ->implode('  |  ');
    }
}
