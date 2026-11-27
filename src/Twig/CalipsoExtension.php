<?php

namespace App\Twig;

use App\Catalog\Catalog;
use App\EasyVista\CatalogCodeResolver;
use App\EasyVista\EasyVistaClientInterface;
use App\Service\Counters;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

final class CalipsoExtension extends AbstractExtension implements GlobalsInterface
{
    private const ICONS = [
        'wrench' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M15 8l2 2"/>',
        'laptop' => '<rect x="4" y="5" width="16" height="11" rx="1.5"/><path d="M2 19h20"/>',
        'box' => '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>',
        'cal' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'people' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'euro' => '<path d="M18 6a7 7 0 1 0 0 12"/><path d="M4 10h10M4 14h10"/>',
        'bulb' => '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3z"/>',
        'cube' => '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/>',
        'arrow' => '<path d="M9 6l6 6-6 6"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    ];

    /** @param array<string, mixed> $portal */
    public function __construct(
        private readonly array $portal,
        private readonly Counters $counters,
        private readonly EasyVistaClientInterface $easyVista,
        private readonly Catalog $catalog,
        private readonly CatalogCodeResolver $codes,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('icon', $this->icon(...), ['is_safe' => ['html']]),
            new TwigFunction('calipso_counters', $this->counters->get(...)),
        ];
    }

    public function getGlobals(): array
    {
        return [
            'portail' => $this->portal,
            'easyvista_simule' => $this->easyVista->isMock(),
            'catalogue' => $this->catalog,
            'prefixe_incidents' => $this->codes->incidentPrefix(),
        ];
    }

    public function icon(string $name, string $class = ''): string
    {
        return sprintf('<svg viewBox="0 0 24 24" aria-hidden="true" class="%s">%s</svg>', htmlspecialchars($class), self::ICONS[$name] ?? '');
    }
}
