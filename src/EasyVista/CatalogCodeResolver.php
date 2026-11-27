<?php

namespace App\EasyVista;

use App\Catalog\Model\IncidentSubject;
use App\EasyVista\Exception\CatalogCodeNotFoundException;
use App\Text\TextNormalizer;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Retrouve le code catalogue EasyVista (Catalog_Code de POST /requests) d'un sujet d'incident ou d'un service.
 *
 * Ordre de recherche :
 *  1. code présent dans data/catalogue.json (colonne code de l'export CSV) ;
 *  2. table data/catalog-codes.json produite par « php bin/console calipso:catalogue:sync-codes » ;
 *  3. interrogation de l'API (GET /catalog-requests), mise en cache 24 h.
 */
class CatalogCodeResolver
{
    /** @var array{titles: array<string, string>, paths: array<string, string>}|null */
    private ?array $codes = null;

    /** @param array<string, mixed> $easyvistaOptions */
    public function __construct(
        private readonly EasyVistaClientInterface $client,
        private readonly CacheInterface $cache,
        private readonly string $codesFile,
        private readonly array $easyvistaOptions,
    ) {
    }

    public function incidentPrefix(): string
    {
        return (string) ($this->easyvistaOptions['prefixe_incidents'] ?? 'Incidents/IT');
    }

    /** @throws CatalogCodeNotFoundException */
    public function forIncident(?IncidentSubject $subject): string
    {
        if (null === $subject) {
            $default = (string) ($this->easyvistaOptions['code_incident_defaut'] ?? '');
            if ('' !== $default) {
                return $default;
            }

            return $this->lookup('Sujet par défaut', $this->incidentPrefix().'/Sujet par défaut');
        }

        return $this->lookup($subject->name, $subject->catalogPath($this->incidentPrefix()));
    }

    /** @throws CatalogCodeNotFoundException */
    public function forService(string $title, ?string $knownCode = null): string
    {
        if (null !== $knownCode && '' !== $knownCode) {
            return $knownCode;
        }
        if ('Demande générique' === $title && '' !== (string) ($this->easyvistaOptions['code_demande_generique'] ?? '')) {
            return (string) $this->easyvistaOptions['code_demande_generique'];
        }

        return $this->lookup($title, null);
    }

    private function lookup(string $title, ?string $path): string
    {
        $codes = $this->codes();
        if (null !== $path && isset($codes['paths'][TextNormalizer::key($path)])) {
            return $codes['paths'][TextNormalizer::key($path)];
        }
        if (null === $path && isset($codes['titles'][TextNormalizer::key($title)])) {
            return $codes['titles'][TextNormalizer::key($title)];
        }

        $cacheKey = 'calipso_code_'.($this->client->isMock() ? 'mock_' : 'api_').md5(($path ?? '').'|'.$title);
        $code = $this->cache->get($cacheKey, function (ItemInterface $item) use ($title, $path): string {
            $code = (string) $this->client->findCatalogCode($title, $path);
            $item->expiresAfter('' === $code ? 300 : 86400);

            return $code;
        });

        if ('' === $code) {
            throw new CatalogCodeNotFoundException(sprintf('« %s » n\'est pas trouvé dans le catalogue EasyVista.', $path ?? $title));
        }

        return $code;
    }

    /** @return array{titles: array<string, string>, paths: array<string, string>} */
    private function codes(): array
    {
        if (null === $this->codes) {
            $this->codes = ['titles' => [], 'paths' => []];
            if (is_file($this->codesFile)) {
                $data = json_decode((string) file_get_contents($this->codesFile), true);
                if (\is_array($data)) {
                    $this->codes['titles'] = (array) ($data['titles'] ?? []);
                    $this->codes['paths'] = (array) ($data['paths'] ?? []);
                }
            }
        }

        return $this->codes;
    }
}
