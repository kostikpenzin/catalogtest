<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\PropertyCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:seed-catalog',
    description: 'Seed tariffs into one of three catalog implementations',
    help: <<<HELP
The command seeds a deterministic dataset of tariffs into one of three catalog
implementations and prints variant/target/batch at startup.

  <info>php %command.full_name% --variant=flat --count=100000 --truncate</info>
  <info>php %command.full_name% --variant=rel</info>
  <info>php %command.full_name% --variant=jsonb -c 50000</info>

Default count is 100 000. All three variants use the same batch size (1 000
tariffs per transaction) so the seeding cost is comparable.
HELP,
)]
final class SeedCatalogCommand extends Command
{
    private const TARGET = 100_000;

    /** All three variants use the same batch size (in tariff rows) for fair timing. */
    private const BATCH_TARIFFS = 1_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('variant', null, InputOption::VALUE_REQUIRED, 'flat|rel|jsonb', 'flat')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'How many tariffs to seed (default: ' . self::TARGET . ')', (string) self::TARGET)
            ->addOption('truncate', null, InputOption::VALUE_NONE, 'Truncate target table first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '2048M');
        $io = new SymfonyStyle($input, $output);
        $variant = $input->getOption('variant');
        if (!in_array($variant, ['flat', 'rel', 'jsonb'], true)) {
            $io->error('Unknown variant. Use flat, rel or jsonb.');
            return Command::FAILURE;
        }

        $countOpt = (int) $input->getOption('count');
        $target = $countOpt > 0 ? $countOpt : self::TARGET;
        $batch = self::BATCH_TARIFFS;
        $io->writeln(sprintf('Variant: <info>%s</info>, target: <info>%d</info> tariffs, batch: <info>%d</info>', $variant, $target, $batch));

        $conn = $this->em->getConnection();
        if ($input->getOption('truncate')) {
            $io->writeln('Truncating target tables...');
            if ($variant === 'rel') {
                $conn->executeStatement('TRUNCATE TABLE tariff_value, tariff_property, tariff_rel RESTART IDENTITY CASCADE');
            } else {
                $conn->executeStatement('TRUNCATE TABLE ' . $this->tableFor($variant) . ' RESTART IDENTITY');
            }
        }

        $existing = $this->countRows($conn, $variant);
        if ($existing >= $target) {
            $io->success(sprintf('Variant %s already has %d rows (target %d). Skipping.', $variant, $existing, $target));
            return Command::SUCCESS;
        }

        $started = hrtime(true);
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20260607));

        match ($variant) {
            'flat' => $this->seedFlat($conn, $io, $existing, $rng, $target),
            'rel' => $this->seedRel($conn, $io, $existing, $rng, $target),
            'jsonb' => $this->seedJsonb($conn, $io, $existing, $rng, $target),
        };

        $elapsed = (hrtime(true) - $started) / 1e6;
        $io->success(sprintf('Variant %s: %d rows in %.1f s (%.0f rows/s)',
            $variant, $target, $elapsed / 1000, $target / ($elapsed / 1e6)));

        $this->writeSeedingLog($variant, $target, $elapsed);

        return Command::SUCCESS;
    }

    private function writeSeedingLog(string $variant, int $rows, float $elapsedMs): void
    {
        $logPath = $this->kernel->getProjectDir() . '/var/seeding.json';
        $all = is_file($logPath) ? json_decode((string) file_get_contents($logPath), true) : [];
        if (!is_array($all)) $all = [];
        $all[$variant] = [
            'rows' => $rows,
            'elapsed_ms' => round($elapsedMs, 2),
            'rows_per_sec' => round($rows / ($elapsedMs / 1000), 1),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        @mkdir(dirname($logPath), 0775, true);
        file_put_contents($logPath, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function tableFor(string $variant): string
    {
        return match ($variant) {
            'flat' => 'tariff_flat',
            'rel' => 'tariff_rel',
            'jsonb' => 'tariff_jsonb',
        };
    }

    private function countRows(Connection $conn, string $variant): int
    {
        return (int) $conn->executeQuery('SELECT COUNT(*) FROM ' . $this->tableFor($variant))->fetchOne();
    }

    /**
     * Build the SQL VALUES tuples for a single tariff row of the given variant.
     *
     * - flat  : returns 1 tuple string for tariff_flat  (name + 100 property cells)
     * - jsonb : returns 1 tuple string for tariff_jsonb (name + 1 JSONB cell)
     * - rel   : returns list of 100 tuple strings for tariff_value
     *           (caller does not append a tariff_rel tuple here — handled separately
     *            because the tariff_id is needed and comes from RETURNING id).
     */
    private function buildTariffRow(\Random\Randomizer $rng, int $tariffIndex, string $variant): array
    {
        $name = "'tariff_" . ($tariffIndex + 1) . "'";

        if ($variant === 'flat') {
            $cells = [$name];
            foreach (PropertyCatalog::all() as $p) {
                if ($p['type'] === 'bool') {
                    $cells[] = (($rng->getInt(0, PHP_INT_MAX) & 1) === 1) ? 'TRUE' : 'FALSE';
                } elseif ($p['type'] === 'int') {
                    $cells[] = (string) $rng->getInt(0, 1000);
                } else {
                    $cells[] = "'" . $this->randString($rng) . "'";
                }
            }
            return ['(' . implode(',', $cells) . ')'];
        }

        if ($variant === 'jsonb') {
            $props = [];
            foreach (PropertyCatalog::all() as $p) {
                if ($p['type'] === 'bool') {
                    $props[$p['code']] = (($rng->getInt(0, PHP_INT_MAX) & 1) === 1);
                } elseif ($p['type'] === 'int') {
                    $props[$p['code']] = $rng->getInt(0, 1000);
                } else {
                    $props[$p['code']] = $this->randString($rng);
                }
            }
            $json = "'" . json_encode($props, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "'::jsonb";
            return ['(' . $name . ',' . $json . ')'];
        }

        // rel: caller only uses the random calls for deterministic seeding of property values;
        // the actual tariff_id will be supplied by the caller after the RETURNING step.
        // We return a "value row" list keyed by property code, then the caller fills in ids.
        $cells = [];
        foreach (PropertyCatalog::all() as $p) {
            $b = 'NULL'; $i = 'NULL'; $s = 'NULL';
            if ($p['type'] === 'bool') {
                $b = (($rng->getInt(0, PHP_INT_MAX) & 1) === 1) ? 'TRUE' : 'FALSE';
            } elseif ($p['type'] === 'int') {
                $i = (string) $rng->getInt(0, 1000);
            } else {
                $s = "'" . $this->randString($rng) . "'";
            }
            $cells[] = [$b, $i, $s];
        }
        return $cells;
    }

    private function randString(\Random\Randomizer $rng): string
    {
        return 'str_' . substr(hash('xxh3', 's' . $rng->getInt(0, PHP_INT_MAX)), 0, 8);
    }

    private function seedFlat(Connection $conn, SymfonyStyle $io, int $offset, \Random\Randomizer $rng, int $target): void
    {
        $cols = ['name'];
        foreach (PropertyCatalog::all() as $p) {
            $cols[] = match ($p['type']) {
                'bool' => 'p_b_' . substr($p['code'], 1),
                'int' => 'p_i_' . substr($p['code'], 1),
                'string' => 'p_s_' . substr($p['code'], 1),
            };
        }
        $insertCols = implode(',', $cols);

        for ($start = $offset; $start < $target; $start += self::BATCH_TARIFFS) {
            $count = min(self::BATCH_TARIFFS, $target - $start);
            $tuples = [];
            for ($i = 0; $i < $count; $i++) {
                $tuples[] = $this->buildTariffRow($rng, $start + $i, 'flat')[0];
            }

            $conn->beginTransaction();
            $sql = sprintf('INSERT INTO tariff_flat (%s) VALUES %s', $insertCols, implode(',', $tuples));
            $conn->executeStatement($sql);
            $conn->commit();
            $tuples = null;

            if (($start + $count) % 50_000 === 0 || $start + $count === $target) {
                $io->writeln(sprintf('  flat: %d / %d', $start + $count, $target));
            }
        }
    }

    private function seedJsonb(Connection $conn, SymfonyStyle $io, int $offset, \Random\Randomizer $rng, int $target): void
    {
        for ($start = $offset; $start < $target; $start += self::BATCH_TARIFFS) {
            $count = min(self::BATCH_TARIFFS, $target - $start);
            $tuples = [];
            for ($i = 0; $i < $count; $i++) {
                $tuples[] = $this->buildTariffRow($rng, $start + $i, 'jsonb')[0];
            }

            $conn->beginTransaction();
            $conn->executeStatement('INSERT INTO tariff_jsonb (name, props) VALUES ' . implode(',', $tuples));
            $conn->commit();
            $tuples = null;

            if (($start + $count) % 50_000 === 0 || $start + $count === $target) {
                $io->writeln(sprintf('  jsonb: %d / %d', $start + $count, $target));
            }
        }
    }

    private function seedRel(Connection $conn, SymfonyStyle $io, int $offset, \Random\Randomizer $rng, int $target): void
    {
        if ($offset === 0) {
            $io->writeln('  rel: populating tariff_property...');
            $conn->beginTransaction();
            $propBuf = [];
            foreach (PropertyCatalog::all() as $p) {
                $propBuf[] = sprintf("('%s', '%s')", $p['code'], $p['type']);
            }
            $conn->executeStatement('INSERT INTO tariff_property (code, value_type) VALUES ' . implode(',', $propBuf));
            $conn->commit();
            $propBuf = null;
        }

        $propertyIds = [];
        foreach ($conn->fetchAllAssociative('SELECT id, code, value_type FROM tariff_property') as $row) {
            $propertyIds[$row['code']] = (int) $row['id'];
        }
        $propertyCols = PropertyCatalog::all();

        for ($start = $offset; $start < $target; $start += self::BATCH_TARIFFS) {
            $count = min(self::BATCH_TARIFFS, $target - $start);

            $conn->beginTransaction();

            // Step 1: bulk-insert $count rows into tariff_rel, get IDs back.
            $relNames = [];
            for ($i = 0; $i < $count; $i++) {
                $relNames[] = "'tariff_" . ($start + $i + 1) . "'";
            }
            $sql = 'INSERT INTO tariff_rel (name) VALUES ' . implode(',', array_map(fn($n) => "($n)", $relNames)) . ' RETURNING id';
            $newIds = $conn->fetchFirstColumn($sql);
            $relNames = null;

            // Step 2: build value rows for all tariffs in the batch (100 × count).
            $valueBuf = [];
            foreach ($newIds as $idx => $tariffId) {
                $tariffId = (int) $tariffId;
                $cells = $this->buildTariffRow($rng, $start + $idx, 'rel');
                foreach ($propertyCols as $pIdx => $p) {
                    [$b, $i, $s] = $cells[$pIdx];
                    $valueBuf[] = sprintf('(%d, %d, %s, %s, %s)', $tariffId, $propertyIds[$p['code']], $b, $i, $s);
                }
            }
            $newIds = null;

            // Step 3: bulk-insert all value rows in a single statement.
            $conn->executeStatement('INSERT INTO tariff_value (tariff_id, property_id, value_bool, value_int, value_string) VALUES ' . implode(',', $valueBuf));
            $valueBuf = null;

            $conn->commit();

            if (($start + $count) % 10_000 === 0 || $start + $count === $target) {
                $io->writeln(sprintf('  rel: %d / %d', $start + $count, $target));
            }
        }
    }
}
