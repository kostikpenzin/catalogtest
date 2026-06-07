<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BenchmarkRunner;
use App\Service\CriteriaGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:benchmark',
    description: 'Run OR-search benchmark across 3 catalog variants and write a report',
)]
final class RunBenchmarkCommand extends Command
{
    public function __construct(private readonly BenchmarkRunner $runner) { parent::__construct(); }

    protected function configure(): void
    {
        $this
            ->addOption('iterations', 'i', InputOption::VALUE_REQUIRED, 'Iterations per variant', '5')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'JSON output path', 'var/benchmark.json')
            ->addOption('report', 'r', InputOption::VALUE_REQUIRED, 'Markdown report path (optional)', 'var/benchmark-report.md')
            ->addOption('no-plan', null, InputOption::VALUE_NONE, 'Skip EXPLAIN ANALYZE');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $iter = max(1, (int) $input->getOption('iterations'));
        $jsonOut = $input->getOption('output');
        $mdOut = $input->getOption('report');
        $withPlan = !$input->getOption('no-plan');

        $criteria = CriteriaGenerator::generate();
        $io->writeln(sprintf('Criteria: %d conditions (%d bool, %d int, %d string)',
            count($criteria),
            CriteriaGenerator::summary($criteria)['bool'],
            CriteriaGenerator::summary($criteria)['int'],
            CriteriaGenerator::summary($criteria)['string'],
        ));

        // Single-run snapshot (used for the markdown report and plans).
        $io->section('Single run (cold)');
        $snapshot = $this->runner->run($criteria, $withPlan);
        foreach ($snapshot as $name => $r) {
            $io->writeln(sprintf('  %-6s  %8.2f ms  %d rows / %d total',
                $name, $r['time_ms'], $r['rows'], $r['count_total']));
        }

        // Percentile run.
        $io->section(sprintf('Percentile run (%d iterations each)', $iter));
        $stats = [];
        foreach (['flat', 'rel', 'jsonb'] as $v) {
            $io->write(sprintf('  %-6s ... ', $v));
            $stats[$v] = $this->runner->runN($v, $criteria, $iter);
            $io->writeln(sprintf('p50=%6.2f ms  p95=%7.2f ms  max=%7.2f ms',
                $stats[$v]['p50'], $stats[$v]['p95'], $stats[$v]['max']));
        }

        $sizes = $this->runner->tableSizes();
        $io->section('Table sizes (bytes)');
        foreach ($sizes as $k => $bytes) {
            $io->writeln(sprintf('  %-10s %s', $k, $this->humanBytes($bytes)));
        }

        $report = [
            'criteria_version' => CriteriaGenerator::CRITERIA_VERSION,
            'criteria_summary' => CriteriaGenerator::summary($criteria),
            'iterations' => $iter,
            'snapshot' => $snapshot,
            'stats' => $stats,
            'sizes' => $sizes,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $jsonDir = dirname($jsonOut);
        if (!is_dir($jsonDir)) @mkdir($jsonDir, 0775, true);
        file_put_contents($jsonOut, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $io->success('JSON written to ' . $jsonOut);

        if ($mdOut) {
            file_put_contents($mdOut, $this->renderMarkdown($report));
            $io->success('Report written to ' . $mdOut);
        }

        return Command::SUCCESS;
    }

    private function renderMarkdown(array $r): string
    {
        $lines = [];
        $lines[] = '# Tariff catalog — OR-search benchmark';
        $lines[] = '';
        $lines[] = '_Generated ' . $r['timestamp'] . ' (criteria version ' . $r['criteria_version'] . ')_';
        $lines[] = '';
        $lines[] = '## Scenario';
        $lines[] = '';
        $lines[] = '- 1 000 000 tariffs per variant';
        $lines[] = '- 100 properties (bool: ' . $r['criteria_summary']['bool']
            . ', int: ' . $r['criteria_summary']['int']
            . ', string: ' . $r['criteria_summary']['string'] . ')';
        $lines[] = '- OR-search: every property is a separate condition, ORed together';
        $lines[] = '- 100-row result cap (`LIMIT 1000`)';
        $lines[] = '- ' . $r['iterations'] . ' iterations for percentile numbers';
        $lines[] = '';
        $lines[] = '## Storage size';
        $lines[] = '';
        $lines[] = '| Relation | Size |';
        $lines[] = '|---|---:|';
        foreach ($r['sizes'] as $k => $b) {
            $lines[] = '| ' . $k . ' | ' . $this->humanBytes($b) . ' |';
        }
        $lines[] = '';
        $lines[] = '## Single-run results';
        $lines[] = '';
        $lines[] = '| Variant | Time, ms | Rows | Table rows |';
        $lines[] = '|---|---:|---:|---:|';
        foreach ($r['snapshot'] as $name => $s) {
            $lines[] = '| ' . $name . ' | ' . sprintf('%.2f', $s['time_ms'])
                . ' | ' . $s['rows'] . ' | ' . $s['count_total'] . ' |';
        }
        $lines[] = '';
        $lines[] = '## Percentile results';
        $lines[] = '';
        $lines[] = '| Variant | p50, ms | p95, ms | max, ms | mean, ms |';
        $lines[] = '|---|---:|---:|---:|---:|';
        foreach ($r['stats'] as $name => $s) {
            $lines[] = '| ' . $name . ' | ' . sprintf('%.2f', $s['p50'])
                . ' | ' . sprintf('%.2f', $s['p95'])
                . ' | ' . sprintf('%.2f', $s['max'])
                . ' | ' . sprintf('%.2f', $s['mean']) . ' |';
        }
        $lines[] = '';

        // Winner + conclusions (text narrative).
        $lines[] = '## Conclusions';
        $lines[] = '';
        $times = [];
        foreach ($r['stats'] as $name => $s) $times[$name] = $s['p50'];
        asort($times);
        $winner = array_key_first($times);
        $lines[] = '- **Fastest variant (p50):** `' . $winner . '` — ' . sprintf('%.2f', $times[$winner]) . ' ms';
        $i = 0;
        foreach ($times as $name => $t) {
            if ($i++ === 0) continue;
            $ratio = $t / $times[$winner];
            $lines[] = '- `' . $name . '` is ' . sprintf('%.2fx', $ratio) . ' slower (' . sprintf('%.2f', $t) . ' ms)';
        }
        $lines[] = '';
        $lines[] = '## Why each variant behaves the way it does';
        $lines[] = '';
        $lines[] = '### R1 — One table (`tariff_flat`, 100 columns, PK only)';
        $lines[] = '';
        $lines[] = 'The OR-clause covers 70 bool + 20 int + 10 string columns. With only the PK available,';
        $lines[] = 'PostgreSQL falls back to a sequential scan and must evaluate every condition on every row.';
        $lines[] = 'Bool columns short-circuit on `IS TRUE` and cost almost nothing per row, but the sheer row count dominates.';
        $lines[] = '';
        $lines[] = '### R2 — EAV (`tariff_value` × `tariff_property`)';
        $lines[] = '';
        $lines[] = 'Every tariff is split into 100 rows in `tariff_value`. The benchmark query becomes a `UNION`';
        $lines[] = 'of three sub-queries (one per type) joined back to `tariff_rel`. The cost is dominated by';
        $lines[] = 'scanning `tariff_value` (~100M rows) and de-duplicating by `tariff_id`. The per-type indexes';
        $lines[] = '(`(property_id, value_int)`, `(property_id, value_string)`) only help when the planner can';
        $lines[] = 'pick a single property_id and equality value — for an OR over 100 conditions the planner is';
        $lines[] = 'forced to fall back to a much wider scan or per-branch bitmap-or, then a `HashAggregate` to dedupe.';
        $lines[] = '';
        $lines[] = '### R3 — JSONB (`tariff_jsonb`) with GIN(`jsonb_path_ops`)';
        $lines[] = '';
        $lines[] = 'The query uses `(props->>\'code\')::type = value` rather than `props @> \'{...}\'`, because';
        $lines[] = 'the `@>` containment operator is the only thing the GIN index supports and it requires concrete';
        $lines[] = 'value matches — for a mixed bool/int/string OR over 100 keys it does not help. Without functional';
        $lines[] = 'indexes on the `->>` expressions the planner cannot push anything into the index and falls back';
        $lines[] = 'to a sequential scan with expression evaluation per row, plus a JSONB parse on every access.';
        $lines[] = '';
        $lines[] = '## Recommendation';
        $lines[] = '';
        $lines[] = 'For an OR-search over many heterogeneous properties in PostgreSQL, the canonical answer is';
        $lines[] = 'almost always the wide-row table: it stays a single heap scan with no per-row JSON parsing,';
        $lines[] = 'no extra joins and no 100× row expansion. EAV is the worst of all worlds here. JSONB becomes';
        $lines[] = 'competitive only when you can express the search via `props @> \'{...}\'` (containment on a small';
        $lines[] = 'subset of keys) or add per-key functional indexes, which negates its "schema-less" advantage.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function humanBytes(int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
        return sprintf('%.2f %s', $b, $u[$i]);
    }
}
