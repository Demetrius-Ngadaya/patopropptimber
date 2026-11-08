<?php

namespace App\Services\Core;

use App\Models\Core\Branch;
use App\Services\Messaging\Messenger;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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
        $head = $this->letterhead(__($title), $filters);

        return $format === 'pdf'
            ? $this->pdf($filename, $head, $columns, $rows, $totals)
            : $this->xlsx($filename, $head, $columns, $rows, $totals);
    }

    /**
     * What goes at the top of every exported report: logo, business name and details,
     * the report title, the branch, the period, the other filters and who printed it.
     */
    private function letterhead(string $title, array $filters): array
    {
        $brand = app(Messenger::class)->brand('report');
        $branchId = app(BranchContext::class)->id();
        $branch = $branchId ? Branch::query()->whereKey($branchId)->value('name') : null;
        $from = $filters['from'] ?? $filters['date_from'] ?? null;
        $to = $filters['to'] ?? $filters['date_to'] ?? null;
        $period = $from || $to
            ? __('Period').': '.($from ? Carbon::parse($from)->format('d/m/Y') : '…').' – '.($to ? Carbon::parse($to)->format('d/m/Y') : '…')
            : null;
        $other = collect($filters)->except(['from', 'to', 'date_from', 'date_to', 'export', 'scope', 'page', 'per_page'])
            ->filter(fn ($v, $k) => $v !== null && $v !== '' && $v !== [] && ! Str::endsWith((string) $k, '_id'))
            ->map(fn ($v, $k) => __(Str::headline($k)).': '.(is_array($v) ? implode(', ', $v) : __((string) Str::headline((string) $v))))
            ->implode('  ·  ');

        return [
            'title' => $title,
            'name' => $brand['name'] ?: (string) config('app.name'),
            'contact' => Messenger::contactLine($brand),
            'logo_path' => $brand['logo_path'],
            'color' => $brand['color'],
            'branch' => $branch ? __('Branch').': '.$branch : __('All branches'),
            'period' => $period,
            'filters' => $other,
            'printed' => __('Printed').': '.now()->format('d/m/Y H:i').(auth()->user() ? ' · '.auth()->user()->name : ''),
            'powered_by' => (string) config('mauzo.brand.powered_by'),
        ];
    }

    private function xlsx(string $filename, array $head, array $columns, iterable $rows, array $totals): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(Str::limit(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $head['title']), 28, '') ?: 'Report');
        $count = max(1, count($columns));
        $lastCol = Coordinate::stringFromColumnIndex($count);
        $accent = ltrim($head['color'] ?: '#157a55', '#');

        // Letterhead: centred across the whole table width.
        $r = 1;
        if ($head['logo_path'] && is_readable($head['logo_path'])) {
            $sheet->getRowDimension(1)->setRowHeight(48);
            $drawing = new Drawing;
            $drawing->setPath($head['logo_path']);
            $drawing->setHeight(56);
            // Roughly the middle column, so the logo sits above the centred name.
            $drawing->setCoordinates(Coordinate::stringFromColumnIndex(max(1, (int) ceil($count / 2))).'1');
            $drawing->setWorksheet($sheet);
            $r = 2;
        }
        $lines = [
            [$head['name'], 16, true, $accent],
            [$head['contact'], 9, false, '4B5563'],
            [Str::upper($head['title']), 13, true, '111827'],
            [implode('   ·   ', array_filter([$head['branch'], $head['period']])), 10, true, '374151'],
            [$head['filters'], 9, false, '4B5563'],
            [$head['printed'], 8, false, '6B7280'],
        ];
        foreach ($lines as [$text, $size, $bold, $color]) {
            if ($text === '' || $text === null) {
                continue;
            }
            $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
            $sheet->setCellValueExplicit("A{$r}", (string) $text, DataType::TYPE_STRING);
            $style = $sheet->getStyle("A{$r}");
            $style->getFont()->setSize($size)->setBold($bold)->getColor()->setRGB($color);
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $r++;
        }

        $rowIndex = $r + 1;
        $headerRow = $rowIndex;
        $col = 1;
        foreach ($columns as $column) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($col++).$rowIndex, $column['label'], DataType::TYPE_STRING);
        }
        $headerRange = "A{$rowIndex}:{$lastCol}{$rowIndex}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($accent);
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

        foreach ($rows as $row) {
            $row = $this->toArray($row);
            $rowIndex++;
            $col = 1;
            foreach ($columns as $key => $column) {
                $value = data_get($row, $key);
                $cell = Coordinate::stringFromColumnIndex($col++).$rowIndex;
                if (in_array($column['type'], ['money', 'number'], true) && is_numeric($value)) {
                    $sheet->setCellValue($cell, (float) $value);
                } else {
                    // Explicit string type: user text starting with = + - @ is never treated as a formula.
                    $sheet->setCellValueExplicit($cell, $this->cell($row, $key, $column['type']), DataType::TYPE_STRING);
                }
            }
        }
        // Number formats per column in one go (much faster than per cell).
        $col = 1;
        foreach ($columns as $column) {
            if (in_array($column['type'], ['money', 'number'], true) && $rowIndex > $headerRow) {
                $letter = Coordinate::stringFromColumnIndex($col);
                $sheet->getStyle("{$letter}".($headerRow + 1).":{$letter}".($rowIndex + 1))->getNumberFormat()
                    ->setFormatCode($column['type'] === 'money' ? '#,##0.00' : '#,##0.###');
                $sheet->getStyle("{$letter}{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $col++;
        }

        if ($totals) {
            $rowIndex++;
            $col = 1;
            foreach ($columns as $key => $column) {
                $cell = Coordinate::stringFromColumnIndex($col++).$rowIndex;
                if ($col === 2) {
                    $sheet->setCellValue($cell, Str::upper(__('Total')));
                }
                if (array_key_exists($key, $totals)) {
                    $sheet->setCellValue($cell, (float) $totals[$key]);
                }
            }
            $totalRange = "A{$rowIndex}:{$lastCol}{$rowIndex}";
            $sheet->getStyle($totalRange)->getFont()->setBold(true);
            $sheet->getStyle($totalRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
            $sheet->getStyle($totalRange)->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);
        }

        foreach (range(1, $count) as $i) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        // Keep the column names visible while scrolling, and allow sorting / filtering.
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}".max($headerRow, $rowIndex - ($totals ? 1 : 0)));
        $sheet->getPageSetup()->setOrientation($count > 7 ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
        $sheet->getHeaderFooter()->setOddFooter('&L'.$head['name'].'&R&P / &N');

        return new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}.xlsx\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    private function pdf(string $filename, array $head, array $columns, iterable $rows, array $totals): Response
    {
        @set_time_limit(300);
        $formatted = [];
        foreach ($rows as $row) {
            $row = $this->toArray($row);
            $line = [];
            foreach ($columns as $key => $column) {
                $line[] = $this->cell($row, $key, $column['type']);
            }
            $formatted[] = $line;
        }
        $cols = array_values(array_map(fn ($c) => ['label' => $c['label'], 'numeric' => in_array($c['type'], ['money', 'number'], true)], $columns));
        $sums = array_values(array_map(fn ($key) => array_key_exists($key, $totals) ? number_format((float) $totals[$key], 2) : null, array_keys($columns)));

        $bytes = app(TablePdf::class)->render($head, $cols, $formatted, $sums, count($columns) > 7);

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}.pdf\"",
            'Cache-Control' => 'no-store',
        ]);
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
                ? ['label' => __((string) $column['label']), 'type' => $column['type'] ?? 'text']
                : ['label' => __((string) $column), 'type' => 'text'];
        }

        return $normalized;
    }

    /** Columns holding a status word (paid, cash, credit ...) shown in the reader's language. */
    private const WORD_COLUMNS = ['payment_status', 'status', 'sale_type', 'channel', 'method', 'payment_method', 'type', 'condition', 'stock_status', 'source'];

    private function cell(array $row, string $key, string $type): string
    {
        $value = data_get($row, $key);
        if (is_string($value) && $value !== '' && in_array(Str::afterLast($key, '.'), self::WORD_COLUMNS, true) && preg_match('/^[a-z_ -]+$/', $value)) {
            return __(Str::ucfirst(str_replace('_', ' ', $value)));
        }

        return $this->format($value, $type);
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
            'bool' => $value ? __('Yes') : __('No'),
            default => is_array($value) ? json_encode($value) : (string) $value,
        };
    }
}
