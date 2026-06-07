<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CriteriaGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ApiController extends AbstractController
{
    #[Route('/api/criteria', name: 'api_criteria', methods: ['GET'])]
    public function criteria(): JsonResponse
    {
        $criteria = CriteriaGenerator::generate();

        return new JsonResponse([
            'version' => CriteriaGenerator::CRITERIA_VERSION,
            'count'   => count($criteria),
            'summary' => CriteriaGenerator::summary($criteria),
            'items'   => $criteria,
        ]);
    }
}
