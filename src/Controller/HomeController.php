<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig');
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(Connection $connection): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1');
            $dbStatus = 'ok';
        } catch (\Throwable $e) {
            $dbStatus = 'error: ' . $e->getMessage();
        }

        return new JsonResponse([
            'status'    => 'ok',
            'php'       => PHP_VERSION,
            'symfony'   => \Symfony\Component\HttpKernel\Kernel::VERSION,
            'database'  => $dbStatus,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
