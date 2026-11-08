<?php

namespace App\Services\Core;

use Dompdf\Cpdf;
use Throwable;

/**
 * Draws a report table straight onto PDF pages (no HTML layout), so even thousands
 * of rows export in a few seconds with little memory, which matters on shared hosting.
 *
 * Page 1 starts with a centred letterhead (logo, business name and details, report
 * title, branch and period). Every page repeats the column names and ends with who
 * printed it and "Page x / y". Numbers are right-aligned; long text is shortened to
 * fit its column.
 */
class TablePdf
{
    private const MARGIN = 28.0;

    private const FOOTER = 24.0;

    private Cpdf $pdf;

    private float $width;

    private float $height;

    private string $regular;

    private string $bold;

    private array $accent;

    /**
     * @param  array  $head  letterhead: name, contact, title, branch, period, filters, printed, powered_by, color, logo_path
     * @param  array<int, array{label: string, numeric: bool}>  $columns
     * @param  array<int, array<int, string>>  $rows  already formatted text
     * @param  array<int, string|null>  $totals  one entry per column (null = empty)
     */
    public function render(array $head, array $columns, array $rows, array $totals, bool $landscape): string
    {
        [$this->width, $this->height] = $landscape ? [841.89, 595.28] : [595.28, 841.89];
        $this->pdf = new Cpdf([0, 0, $this->width, $this->height], false);
        $this->pdf->addInfo('Title', $head['title']);
        $this->pdf->addInfo('Creator', $head['powered_by'] ?: 'PatoPro');
        $fonts = base_path('vendor/dompdf/dompdf/lib/fonts/');
        $this->regular = $fonts.'Helvetica';
        $this->bold = $fonts.'Helvetica-Bold';
        $this->accent = $this->rgb($head['color'] ?: '#157a55');

        $count = max(1, count($columns));
        $size = $count > 12 ? 6.6 : ($count > 9 ? 7.2 : 7.8);
        $rowH = $size + 5.4;
        $widths = $this->columnWidths($columns, $rows, $totals, $size);

        $hasTotals = collect($totals)->filter(fn ($t) => $t !== null && $t !== '')->isNotEmpty();
        $top = $this->letterhead($head);
        $pages = $this->countPages(count($rows) + ($hasTotals ? 1 : 0), $top, $rowH);

        $page = 1;
        $y = $this->tableHeader($top, $columns, $widths, $size, $rowH);
        foreach ($rows as $i => $row) {
            if ($y - $rowH < self::MARGIN + self::FOOTER) {
                $this->footer($head, $page, $pages);
                $this->pdf->newPage();
                $page++;
                $y = $this->tableHeader($this->height - self::MARGIN, $columns, $widths, $size, $rowH);
            }
            if ($i % 2 === 1) {
                $this->fill([0.976, 0.98, 0.984], self::MARGIN, $y - $rowH, array_sum($widths), $rowH);
            }
            $this->cells($row, $columns, $widths, $y, $rowH, $size, false);
            $this->pdf->setStrokeColor([0.9, 0.91, 0.92]);
            $this->pdf->setLineStyle(0.4);
            $this->pdf->line(self::MARGIN, $y - $rowH, self::MARGIN + array_sum($widths), $y - $rowH);
            $y -= $rowH;
        }
        if (! $rows) {
            $this->font(false);
            $this->pdf->setColor([0.42, 0.45, 0.5]);
            $msg = __('No records for the selected filters.');
            $this->pdf->addText(($this->width - $this->pdf->getTextWidth(9, $msg)) / 2, $y - 16, 9, $msg);
            $y -= 24;
        }
        if ($hasTotals) {
            if ($y - $rowH - 2 < self::MARGIN + self::FOOTER) {
                $this->footer($head, $page, $pages);
                $this->pdf->newPage();
                $page++;
                $y = $this->tableHeader($this->height - self::MARGIN, $columns, $widths, $size, $rowH);
            }
            $y -= 2;
            $this->fill([0.953, 0.957, 0.965], self::MARGIN, $y - $rowH - 1, array_sum($widths), $rowH + 1);
            $this->pdf->setStrokeColor([0.07, 0.09, 0.15]);
            $this->pdf->setLineStyle(1);
            $this->pdf->line(self::MARGIN, $y, self::MARGIN + array_sum($widths), $y);
            $line = array_map(fn ($t) => (string) ($t ?? ''), $totals);
            $this->cells($line, $columns, $widths, $y, $rowH, $size, true, true);
            // "TOTAL" uses the empty cells before the first total, so it is never cut short.
            $firstTotal = collect($line)->search(fn ($t) => $t !== '');
            $label = mb_strtoupper(__('Total'));
            if ($firstTotal !== 0) {
                $room = array_sum(array_slice($widths, 0, $firstTotal === false ? count($widths) : $firstTotal));
                $this->font(true);
                $this->pdf->setColor([0.07, 0.09, 0.15]);
                $this->pdf->addText(self::MARGIN + 3, $y - $rowH + ($rowH - $size) / 2 + 1.2, $size, $this->fit($label, $room - 6, $size));
            }
        }
        $this->footer($head, $page, $pages);

        return $this->pdf->output();
    }

    /** Draws the letterhead; returns where the table starts. */
    private function letterhead(array $head): float
    {
        $y = $this->height - self::MARGIN;

        if ($head['logo_path'] ?? null) {
            $drawn = $this->logo($head['logo_path'], $y);
            if ($drawn > 0) {
                $y -= $drawn + 6;
            }
        }
        $y -= 15;
        $this->centered($head['name'], $y, 15, true, $this->accent);
        if (! empty($head['contact'])) {
            $y -= 12;
            $this->centered($head['contact'], $y, 8, false, [0.29, 0.33, 0.39]);
        }
        $y -= 17;
        $this->centered(mb_strtoupper($head['title']), $y, 12, true, [0.07, 0.09, 0.15]);
        $scope = implode('   ·   ', array_filter([$head['branch'] ?? null, $head['period'] ?? null]));
        if ($scope !== '') {
            $y -= 12;
            $this->centered($scope, $y, 8.5, true, [0.22, 0.25, 0.32]);
        }
        if (! empty($head['filters'])) {
            $y -= 10.5;
            $this->centered($head['filters'], $y, 7.5, false, [0.42, 0.45, 0.5]);
        }
        $y -= 7;
        $this->pdf->setStrokeColor($this->accent);
        $this->pdf->setLineStyle(1.6);
        $this->pdf->line(self::MARGIN, $y, $this->width - self::MARGIN, $y);

        return $y - 8;
    }

    private function logo(string $path, float $top): float
    {
        try {
            $info = @getimagesize($path);
            if (! $info || ! $info[0] || ! $info[1]) {
                return 0;
            }
            $h = 40.0;
            $w = $info[0] * $h / $info[1];
            if ($w > 160) {
                $w = 160.0;
                $h = $info[1] * $w / $info[0];
            }
            $x = ($this->width - $w) / 2;
            $y = $top - $h;
            match ($info[2]) {
                IMAGETYPE_PNG => $this->pdf->addPngFromFile($path, $x, $y, $w, $h),
                IMAGETYPE_JPEG => $this->pdf->addJpegFromFile($path, $x, $y, $w, $h),
                default => throw new \RuntimeException('unsupported'),
            };

            return $h;
        } catch (Throwable) {
            return 0; // A logo the PDF cannot use (e.g. WebP/SVG) is simply left out.
        }
    }

    private function tableHeader(float $y, array $columns, array $widths, float $size, float $rowH): float
    {
        $h = $rowH + 3;
        $this->fill($this->accent, self::MARGIN, $y - $h, array_sum($widths), $h);
        $this->pdf->setColor([1, 1, 1]);
        $this->font(true);
        $x = self::MARGIN;
        foreach ($columns as $i => $c) {
            $text = $this->fit($c['label'], $widths[$i] - 6, $size);
            $tx = $c['numeric'] ? $x + $widths[$i] - 3 - $this->pdf->getTextWidth($size, $text) : $x + 3;
            $this->pdf->addText($tx, $y - $h + ($h - $size) / 2 + 1.2, $size, $text);
            $x += $widths[$i];
        }

        return $y - $h;
    }

    private function cells(array $row, array $columns, array $widths, float $y, float $rowH, float $size, bool $bold, bool $totals = false): void
    {
        $this->font($bold);
        $this->pdf->setColor([0.07, 0.09, 0.15]);
        $x = self::MARGIN;
        foreach ($columns as $i => $c) {
            $text = $this->fit((string) ($row[$i] ?? ''), $widths[$i] - 6, $size);
            if ($text !== '') {
                $right = $c['numeric'] || ($totals && $i > 0);
                $tx = $right ? $x + $widths[$i] - 3 - $this->pdf->getTextWidth($size, $text) : $x + 3;
                $this->pdf->addText($tx, $y - $rowH + ($rowH - $size) / 2 + 1.2, $size, $text);
            }
            $x += $widths[$i];
        }
    }

    private function footer(array $head, int $page, int $pages): void
    {
        $this->font(false);
        $this->pdf->setColor([0.45, 0.47, 0.5]);
        $y = self::MARGIN - 6;
        $left = trim(($head['printed'] ?? '').(! empty($head['powered_by']) ? '  ·  '.$head['powered_by'] : ''));
        $this->pdf->addText(self::MARGIN, $y, 6.8, $this->fit($left, $this->width / 1.6, 6.8));
        $right = __('Page').' '.$page.' / '.$pages;
        $this->pdf->addText($this->width - self::MARGIN - $this->pdf->getTextWidth(6.8, $right), $y, 6.8, $right);
        $this->pdf->setStrokeColor([0.85, 0.86, 0.88]);
        $this->pdf->setLineStyle(0.5);
        $this->pdf->line(self::MARGIN, $y + 9, $this->width - self::MARGIN, $y + 9);
    }

    private function countPages(int $lines, float $top, float $rowH): int
    {
        $usable = fn (float $start) => (int) floor(($start - ($rowH + 3) - (self::MARGIN + self::FOOTER)) / $rowH);
        $first = max(1, $usable($top));
        if ($lines <= $first) {
            return 1;
        }
        $other = max(1, $usable($this->height - self::MARGIN));

        return 1 + (int) ceil(($lines - $first) / $other);
    }

    /** Column widths from the widest text (sampled), squeezed to fit the page. */
    private function columnWidths(array $columns, array $rows, array $totals, float $size): array
    {
        $available = $this->width - 2 * self::MARGIN;
        $sample = count($rows) > 400 ? array_merge(array_slice($rows, 0, 300), array_slice($rows, -100)) : $rows;
        $want = [];
        foreach ($columns as $i => $c) {
            $this->font(true);
            $w = min($this->pdf->getTextWidth($size, $c['label']), 90);
            $this->font(false);
            foreach ($sample as $row) {
                $w = max($w, $this->pdf->getTextWidth($size, (string) ($row[$i] ?? '')));
            }
            if (isset($totals[$i])) {
                $this->font(true);
                $w = max($w, $this->pdf->getTextWidth($size, (string) $totals[$i]));
            }
            $want[$i] = min(max($w + 7, 18), $c['numeric'] ? 110 : 190);
        }
        $sum = array_sum($want);
        if ($sum <= $available) {
            // Spread spare room over the text columns.
            $text = array_keys(array_filter($columns, fn ($c) => ! $c['numeric']));
            $spare = $available - $sum;
            foreach ($text ?: array_keys($want) as $i) {
                $want[$i] += $spare / max(1, count($text ?: $want));
            }

            return $want;
        }
        // Too wide: numbers keep their width where possible, text columns shrink.
        $numericTotal = array_sum(array_intersect_key($want, array_filter($columns, fn ($c) => $c['numeric'])));
        $textTotal = $sum - $numericTotal;
        $textRoom = max($available - $numericTotal, $available * 0.35);
        $numFactor = $numericTotal > 0 ? min(1, ($available - $textRoom) / $numericTotal) : 1;
        foreach ($want as $i => $w) {
            $want[$i] = $columns[$i]['numeric'] ? $w * $numFactor : $w * ($textTotal > 0 ? $textRoom / $textTotal : 1);
        }

        return $want;
    }

    private function fit(string $text, float $width, float $size): string
    {
        if ($text === '' || $this->pdf->getTextWidth($size, $text) <= $width) {
            return $text;
        }
        $lo = 0;
        $hi = mb_strlen($text);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($this->pdf->getTextWidth($size, mb_substr($text, 0, $mid).'…') <= $width) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return $lo > 0 ? rtrim(mb_substr($text, 0, $lo)).'…' : '';
    }

    private function centered(string $text, float $y, float $size, bool $bold, array $color): void
    {
        $this->font($bold);
        $this->pdf->setColor($color);
        $text = $this->fit($text, $this->width - 2 * self::MARGIN, $size);
        $this->pdf->addText(($this->width - $this->pdf->getTextWidth($size, $text)) / 2, $y, $size, $text);
    }

    private function fill(array $color, float $x, float $y, float $w, float $h): void
    {
        $this->pdf->setColor($color);
        $this->pdf->filledRectangle($x, $y, $w, $h);
    }

    private function font(bool $bold): void
    {
        $this->pdf->selectFont($bold ? $this->bold : $this->regular);
    }

    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (! preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return [0.08, 0.48, 0.33];
        }

        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }
}
