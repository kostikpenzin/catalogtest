<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class BytesExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('human_bytes', $this->humanBytes(...))];
    }

    public function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $b = (float) $bytes;
        while ($b >= 1024 && $i < count($units) - 1) {
            $b /= 1024;
            $i++;
        }
        return sprintf('%.2f %s', $b, $units[$i]);
    }
}
