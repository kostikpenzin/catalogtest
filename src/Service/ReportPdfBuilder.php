<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\KernelInterface;
use TCPDF;

/**
 * Builds a Russian-language PDF report from var/benchmark.json + var/seeding.json.
 * Uses TCPDF with the embedded DejaVu Sans font (supports Cyrillic out of the box).
 *
 * Layout:
 *  - Page 1: cover + run parameters
 *  - Page 2: seeding, storage, cold search, percentile
 *  - Page 3+: SQL + EXPLAIN ANALYZE for each variant
 *  - Last page: conclusions
 */
final class ReportPdfBuilder
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {}

    public function build(): ?string
    {
        $projectDir = $this->kernel->getProjectDir();
        $reportPath = $projectDir . '/var/benchmark.json';
        $seedingPath = $projectDir . '/var/seeding.json';

        if (!is_file($reportPath)) return null;
        $r = json_decode((string) file_get_contents($reportPath), true);
        $se = is_file($seedingPath) ? json_decode((string) file_get_contents($seedingPath), true) : null;

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Document meta
        $pdf->setCreator('CatalogTest');
        $pdf->setAuthor('CatalogTest');
        $pdf->setTitle('Каталог тарифов — отчёт бенчмарка');
        $pdf->setSubject('Сравнение схем хранения для OR-поиска по 100 свойствам');

        // Margins (mm). Auto page-break with margin.
        $pdf->setMargins(15, 18, 15);
        $pdf->setHeaderMargin(8);
        $pdf->setFooterMargin(10);
        $pdf->setAutoPageBreak(true, 18);

        // Header / footer text in Russian.
        $pdf->setHeaderData('', 0, 'Каталог тарифов', 'Отчёт бенчмарка OR-поиска');
        $pdf->setFooterData([80, 80, 80], [220, 220, 220]);

        // Fonts: regular + bold (both support Cyrillic).
        $fontRegular = 'dejavusans';
        $fontBold = 'dejavusansb';

        $pdf->setHeaderFont([$fontRegular, '', 9]);
        $pdf->setFooterFont([$fontRegular, '', 8]);
        $pdf->setFont($fontRegular, '', 10);

        // ================== PAGE 1: COVER ==================
        $pdf->AddPage();

        $pdf->setFont($fontBold, '', 22);
        $pdf->Cell(0, 12, 'Каталог тарифов', 0, 1, 'L');
        $pdf->setFont($fontBold, '', 14);
        $pdf->Cell(0, 8, 'Отчёт бенчмарка OR-поиска', 0, 1, 'L');
        $pdf->Ln(2);

        $pdf->setFont($fontRegular, '', 10);
        $pdf->MultiCell(0, 5,
            "Сравнение трёх способов хранить «тариф + 100 свойств» в PostgreSQL 16 "
            . "и выполнять по ним OR-поиск с LIMIT 1000.",
            0, 'L', false, 1
        );
        $pdf->Ln(4);

        $pdf->setFont($fontBold, '', 12);
        $pdf->Cell(0, 7, 'Параметры прогона', 0, 1, 'L');
        $pdf->setFont($fontRegular, '', 10);
        $this->table2($pdf, $fontBold, $fontRegular, ['Параметр', 'Значение'], [
            ['Дата отчёта',       $r['timestamp'] ?? '—'],
            ['Версия критериев',  (string) ($r['criteria_version'] ?? '—')],
            ['Итераций на вариант', (string) ($r['iterations'] ?? '—')],
            ['Условий в запросе', sprintf(
                '%d (bool: %d, int: %d, string: %d)',
                ($r['criteria_summary']['bool'] ?? 0)
                    + ($r['criteria_summary']['int'] ?? 0)
                    + ($r['criteria_summary']['string'] ?? 0),
                $r['criteria_summary']['bool'] ?? '?',
                $r['criteria_summary']['int'] ?? '?',
                $r['criteria_summary']['string'] ?? '?',
            )],
            ['Сидер',             'Mt19937(20260607) — детерминированный'],
        ], [55, 115]);

        // ================== PAGE 2: SEEDING, STORAGE, PERF ==================
        $pdf->AddPage();

        $pdf->setFont($fontBold, '', 13);
        $pdf->Cell(0, 7, '1. Сидирование (запись)', 0, 1, 'L');
        $pdf->setFont($fontRegular, '', 9);
        $pdf->MultiCell(0, 4.5,
            'Детерминированный сидер заполняет все три таблицы одинаковыми данными '
            . '(одинаковые id, одинаковые значения свойств), что делает результаты '
            . 'сопоставимыми между реализациями.',
            0, 'L'
        );
        $pdf->Ln(2);

        if (is_array($se) && !empty($se)) {
            $rows = [];
            foreach (['flat', 'rel', 'jsonb'] as $v) {
                if (!isset($se[$v])) continue;
                $rows[] = [
                    $v,
                    number_format($se[$v]['rows'], 0, '.', ' '),
                    sprintf('%.1f с', $se[$v]['elapsed_ms'] / 1000),
                    number_format($se[$v]['rows_per_sec'], 0, '.', ' '),
                ];
            }
            if (!empty($rows)) {
                $this->table2($pdf, $fontBold, $fontRegular,
                    ['Реализация', 'Строк', 'Время', 'Строк/с'], $rows, [42, 42, 42, 44]);
            }
        } else {
            $pdf->MultiCell(0, 4.5,
                "Файл var/seeding.json не найден. Запустите сидер:\n"
                . "  app:seed-catalog --variant=flat|rel|jsonb -c 100000 --truncate",
                0, 'L'
            );
        }
        $pdf->Ln(5);

        $pdf->setFont($fontBold, '', 13);
        $pdf->Cell(0, 7, '2. Объём данных на диске', 0, 1, 'L');
        $pdf->Ln(2);
        $sizeLabels = [
            'flat'      => 'tariff_flat — широкая таблица',
            'rel'       => 'tariff_rel — родитель EAV',
            'rel_value' => 'tariff_value — значения EAV',
            'jsonb'     => 'tariff_jsonb — JSONB',
        ];
        $sizeRows = [];
        foreach (['flat', 'rel', 'rel_value', 'jsonb'] as $k) {
            if (!isset($r['sizes'][$k])) continue;
            $sizeRows[] = [$sizeLabels[$k] ?? $k, $this->humanBytes((int) $r['sizes'][$k])];
        }
        if (!empty($sizeRows)) {
            $this->table2($pdf, $fontBold, $fontRegular, ['Таблица / отношение', 'Размер'], $sizeRows, [110, 60]);
        }
        $pdf->Ln(5);

        $pdf->setFont($fontBold, '', 13);
        $pdf->Cell(0, 7, '3. OR-поиск: одиночный прогон (cold)', 0, 1, 'L');
        $pdf->Ln(2);
        $coldRows = [];
        $coldMin = null;
        foreach (['flat', 'rel', 'jsonb'] as $v) {
            if (!isset($r['snapshot'][$v])) continue;
            $s = $r['snapshot'][$v];
            if ($coldMin === null || $s['time_ms'] < $coldMin) $coldMin = $s['time_ms'];
        }
        foreach (['flat', 'rel', 'jsonb'] as $v) {
            if (!isset($r['snapshot'][$v])) continue;
            $s = $r['snapshot'][$v];
            $ratio = $coldMin > 0 ? $s['time_ms'] / $coldMin : 1.0;
            $label = $v . ($ratio == 1.0 ? ' (лидер)' : '');
            $coldRows[] = [
                $label,
                sprintf('%.2f', $s['time_ms']),
                number_format($s['rows'], 0, '.', ' '),
                number_format($s['count_total'], 0, '.', ' '),
                sprintf('%.2fx', $ratio),
            ];
        }
        if (!empty($coldRows)) {
            $this->table2($pdf, $fontBold, $fontRegular,
                ['Реализация', 'Время, мс', 'Найдено строк', 'Всего в таблице', 'vs лидера'],
                $coldRows, [42, 32, 36, 36, 24]);
        }
        $pdf->Ln(5);

        $pdf->setFont($fontBold, '', 13);
        $pdf->Cell(0, 7, '4. OR-поиск: процентили (' . ($r['iterations'] ?? '?') . ' итераций)', 0, 1, 'L');
        $pdf->setFont($fontRegular, '', 9);
        $pdf->MultiCell(0, 4.5,
            'Каждый вариант прогоняется N раз подряд; ниже — медиана (p50), '
            . '95-й перцентиль и максимум. Лидер — наименьший p50.',
            0, 'L'
        );
        $pdf->Ln(2);
        $pMin = null;
        foreach ($r['stats'] ?? [] as $st) { if ($pMin === null || $st['p50'] < $pMin) $pMin = $st['p50']; }
        $pRows = [];
        foreach (['flat', 'rel', 'jsonb'] as $v) {
            if (!isset($r['stats'][$v])) continue;
            $st = $r['stats'][$v];
            $ratio = $pMin > 0 ? $st['p50'] / $pMin : 1.0;
            $label = $v . ($ratio == 1.0 ? ' (лидер)' : '');
            $pRows[] = [
                $label,
                sprintf('%.2f', $st['p50']),
                sprintf('%.2f', $st['p95']),
                sprintf('%.2f', $st['max']),
                sprintf('%.2f', $st['mean'] ?? $st['p50']),
                sprintf('%.2fx', $ratio),
            ];
        }
        if (!empty($pRows)) {
            $this->table2($pdf, $fontBold, $fontRegular,
                ['Реализация', 'p50, мс', 'p95, мс', 'max, мс', 'mean, мс', 'vs лидера'],
                $pRows, [36, 26, 26, 26, 28, 28]);
        }

        // ================== PAGE 3+: SQL + EXPLAIN ==================
        $pdf->AddPage();
        $pdf->setFont($fontBold, '', 13);
        $pdf->Cell(0, 7, '5. SQL и планы выполнения', 0, 1, 'L');
        $pdf->setFont($fontRegular, '', 9);
        $pdf->MultiCell(0, 4.5,
            'Здесь приведены сгенерированные SQL-запросы и результаты EXPLAIN ANALYZE '
            . 'для каждой реализации. Это помогает понять, почему именно такая разница в скорости.',
            0, 'L'
        );
        $pdf->Ln(3);

        foreach (['flat', 'rel', 'jsonb'] as $v) {
            if (!isset($r['snapshot'][$v])) continue;
            $s = $r['snapshot'][$v];
            $pdf->setFont($fontBold, '', 11);
            $pdf->Cell(0, 6, sprintf('%s — %.2f мс, найдено %d строк', $v, $s['time_ms'], $s['rows']), 0, 1, 'L');
            $pdf->setFont($fontBold, '', 9);
            $pdf->Cell(0, 4, 'SQL:', 0, 1, 'L');
            $pdf->setFont('dejavusansmono', '', 7.5);
            $pdf->MultiCell(0, 3.4, $s['sql'], 0, 'L');
            $pdf->Ln(2);
            if (!empty($s['plan'])) {
                $pdf->setFont($fontBold, '', 9);
                $pdf->Cell(0, 4, 'EXPLAIN ANALYZE:', 0, 1, 'L');
                $pdf->setFont('dejavusansmono', '', 7.5);
                $pdf->MultiCell(0, 3.4, $s['plan'], 0, 'L');
            }
            $pdf->Ln(3);
        }

        // ================== FINAL: CONCLUSIONS ==================
        $pdf->AddPage();
        $pdf->setFont($fontBold, '', 13);
        $pdf->Cell(0, 7, '6. Выводы', 0, 1, 'L');
        $pdf->setFont($fontRegular, '', 10);
        $pdf->MultiCell(0, 5,
            'Все три варианта заполняются одним и тем же детерминированным сидером '
            . '(Mt19937 с фиксированным зерном 20260607) и одинаковыми данными, поэтому '
            . 'id и значения свойств идентичны между реализациями. Это делает сравнение '
            . 'скорости и объёма корректным.',
            0, 'L'
        );
        $pdf->Ln(3);
        $pdf->setFont($fontBold, '', 10);
        $pdf->Cell(0, 5, 'R1 — tariff_flat (102 колонки, только PK).', 0, 1, 'L');
        $pdf->setFont($fontRegular, '', 10);
        $pdf->MultiCell(0, 5,
            "Один последовательный scan таблицы без JOIN и без парсинга JSON. "
            . "На OR по 100 разнородным свойствам это почти всегда самый быстрый вариант. "
            . "Минус: добавление свойства = ALTER TABLE; широкая строка → TOAST-overhead при "
            . "длинных значениях; без вторичных индексов точечные выборки медленные.",
            0, 'L'
        );
        $pdf->Ln(2);
        $pdf->setFont($fontBold, '', 10);
        $pdf->Cell(0, 5, 'R2 — EAV (tariff_rel + tariff_property + tariff_value).', 0, 1, 'L');
        $pdf->setFont($fontRegular, '', 10);
        $pdf->MultiCell(0, 5,
            "Гибкая схема: новое свойство = новая строка в tariff_property. "
            . "Но OR по 100 ключам заставляет планировщик сканировать почти всю tariff_value "
            . "+ делать де-дупликацию через UNION — это самый медленный вариант на таком запросе.",
            0, 'L'
        );
        $pdf->Ln(2);
        $pdf->setFont($fontBold, '', 10);
        $pdf->Cell(0, 5, 'R3 — tariff_jsonb + GIN(jsonb_path_ops).', 0, 1, 'L');
        $pdf->setFont($fontRegular, '', 10);
        $pdf->MultiCell(0, 5,
            "Свойства добавляются без миграций. Но эталонный запрос использует "
            . "(props->>key)::type, а GIN-индекс не ускоряет выбор через ->> и приведение типа — "
            . "планировщик уходит в seq scan + парсинг JSONB. GIN реально помогает только для "
            . "запросов вида props @> {…} (containment), что для «OR по разнородным ключам» не подходит.",
            0, 'L'
        );
        $pdf->Ln(3);
        $pdf->setFont($fontBold, '', 11);
        $pdf->Cell(0, 6, 'Итог', 0, 1, 'L');
        $pdf->setFont($fontRegular, '', 10);
        $pdf->MultiCell(0, 5,
            "Для сценария «OR по многим разнородным свойствам c LIMIT» выигрывает плоская "
            . "таблица (R1) — она же самая компактная на диске. JSONB (R3) занимает ~6× больше "
            . "места, но даёт гибкость схемы. EAV (R2) — самая тяжёлая по объёму и худшая по "
            . "скорости на OR-запросах.",
            0, 'L'
        );
        $pdf->Ln(8);
        $pdf->setFont($fontRegular, '', 9);
        $pdf->Cell(0, 5, '— Конец отчёта —', 0, 1, 'C');

        return $pdf->Output('benchmark-report.pdf', 'S');
    }

    /**
     * Render a 2-column or N-column table with bold header and zebra rows.
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param list<float> $widthsMm column widths in mm
     */
    private function table2(TCPDF $pdf, string $fontBold, string $fontReg, array $headers, array $rows, array $widthsMm): void
    {
        $pdf->setFont($fontBold, '', 9);
        $pdf->SetFillColor(230, 230, 240);
        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetDrawColor(200, 200, 210);
        $pdf->SetLineWidth(0.1);

        $w = $widthsMm;
        // Header
        foreach ($headers as $i => $h) {
            $pdf->Cell($w[$i], 6, $h, 1, 0, 'L', true);
        }
        $pdf->Ln();

        // Rows
        $pdf->setFont($fontReg, '', 9);
        $fill = false;
        foreach ($rows as $row) {
            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 252 : 255);
            foreach ($row as $i => $cell) {
                // Numeric columns right-align: detect by header text.
                $align = (is_numeric(trim((string) $cell)) || str_ends_with((string) ($headers[$i] ?? ''), '×') || str_contains((string) ($headers[$i] ?? ''), 'мс') || str_contains((string) ($headers[$i] ?? ''), 'Размер'))
                    ? 'R' : 'L';
                $pdf->Cell($w[$i], 5.5, (string) $cell, 1, 0, $align, true);
            }
            $pdf->Ln();
            $fill = !$fill;
        }
    }

    private function humanBytes(int $b): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $b;
        while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
        return sprintf('%.2f %s', $v, $units[$i]);
    }
}
