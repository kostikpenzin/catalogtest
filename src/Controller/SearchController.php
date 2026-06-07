<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BenchmarkRunner;
use App\Service\CriteriaGenerator;
use App\Service\ReportPdfBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractController
{
    private const REPORT_PATH = 'var/benchmark.json';

    public function __construct(
        private readonly BenchmarkRunner $runner,
        private readonly KernelInterface $kernel,
        private readonly ReportPdfBuilder $pdfBuilder,
    ) {}

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $reportPath = $this->kernel->getProjectDir() . '/' . self::REPORT_PATH;
        $report = is_file($reportPath)
            ? json_decode((string) file_get_contents($reportPath), true)
            : null;

        $seedingPath = $this->kernel->getProjectDir() . '/var/seeding.json';
        $seeding = is_file($seedingPath)
            ? json_decode((string) file_get_contents($seedingPath), true)
            : null;

        return $this->render('search/index.html.twig', [
            'criteria_summary' => CriteriaGenerator::summary(CriteriaGenerator::generate()),
            'criteria_count' => count(CriteriaGenerator::generate()),
            'report' => $report,
            'report_path' => self::REPORT_PATH,
            'seeding' => $seeding,
        ]);
    }

    #[Route('/search/run', name: 'search_run', methods: ['GET'])]
    public function run(Request $request): JsonResponse
    {
        $criteria = CriteriaGenerator::generate();
        $withPlan = $request->query->getBoolean('plan', true);
        $results = $this->runner->run($criteria, $withPlan);

        $totalMs = 0.0;
        $payload = ['criteria_summary' => CriteriaGenerator::summary($criteria), 'variants' => []];
        foreach ($results as $name => $r) {
            $totalMs += $r['time_ms'];
            $payload['variants'][$name] = [
                'rows' => $r['rows'],
                'time_ms' => round($r['time_ms'], 3),
                'count_total' => $r['count_total'],
                'sql' => $r['sql'],
                'plan' => $r['plan'],
            ];
        }
        $payload['total_ms'] = round($totalMs, 3);
        $payload['timestamp'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        return new JsonResponse($payload);
    }

    #[Route('/benchmark', name: 'benchmark', methods: ['GET'])]
    public function benchmark(): Response
    {
        $path = self::REPORT_PATH;
        $exists = is_file($path);
        $data = $exists ? json_decode((string) file_get_contents($path), true) : null;

        return $this->render('search/benchmark.html.twig', [
            'exists' => $exists,
            'data' => $data,
            'path' => $path,
        ]);
    }

    #[Route('/benchmark.pdf', name: 'benchmark_pdf', methods: ['GET'])]
    public function benchmarkPdf(): Response
    {
        $pdf = $this->pdfBuilder->build();
        if ($pdf === null) {
            return new Response('benchmark.json not found. Run: app:benchmark -i 5', 404);
        }
        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="benchmark-report.pdf"',
        ]);
    }
}
