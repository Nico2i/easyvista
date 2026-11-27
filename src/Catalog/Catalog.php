<?php

namespace App\Catalog;

use App\Catalog\Model\AccessResource;
use App\Catalog\Model\IncidentSubject;
use App\Catalog\Model\ServiceItem;

/**
 * Catalogue compacté (data/catalogue.json), produit par la commande calipso:catalogue:import
 * à partir des exports CSV EasyVista.
 *
 * Format : { inc: [{n, p, f}], svc: [{t, d, g, q, f, c?}], acc: [{r, t, a, ti?}] }
 */
class Catalog
{
    /** @var list<IncidentSubject>|null */
    private ?array $incidents = null;
    /** @var array<string, IncidentSubject> */
    private array $incidentsByKey = [];
    /** @var array<string, IncidentSubject> */
    private array $incidentsByName = [];
    /** @var array<string, ServiceItem> */
    private array $services = [];
    /** @var array<string, AccessResource> */
    private array $access = [];

    /**
     * @param array<string, array{libelle: string, description: string, icone: string}> $families
     * @param array<string, string>                                                     $accessTypes
     * @param array<string, array{libelle: string, filtre?: string, famille?: string}>  $incidentCategories
     */
    public function __construct(
        private readonly string $catalogueFile,
        private readonly array $families,
        private readonly array $accessTypes,
        private readonly array $incidentCategories,
    ) {
    }

    /** @return list<IncidentSubject> */
    public function incidents(): array
    {
        $this->load();

        return $this->incidents;
    }

    public function incident(string $key): ?IncidentSubject
    {
        $this->load();

        return $this->incidentsByKey[$key] ?? null;
    }

    /** Recherche par nom de sujet (utilisée par le dictionnaire de besoins). */
    public function incidentByName(string $name): ?IncidentSubject
    {
        $this->load();

        return $this->incidentsByName[$name] ?? null;
    }

    /** @return array<string, ServiceItem> indexés par intitulé */
    public function services(): array
    {
        $this->load();

        return $this->services;
    }

    public function service(string $title): ?ServiceItem
    {
        $this->load();

        return $this->services[$title] ?? null;
    }

    /** @return list<ServiceItem> */
    public function servicesOfFamily(string $family): array
    {
        return array_values(array_filter($this->services(), static fn (ServiceItem $s) => $s->family === $family));
    }

    /** @return array<string, AccessResource> indexées par nom */
    public function accessResources(): array
    {
        $this->load();

        return $this->access;
    }

    public function accessResource(string $name): ?AccessResource
    {
        $this->load();

        return $this->access[$name] ?? null;
    }

    /** @return array<string, int> nombre de ressources par type */
    public function accessCounts(): array
    {
        $counts = array_fill_keys(array_keys($this->accessTypes), 0);
        foreach ($this->accessResources() as $r) {
            $counts[$r->type] = ($counts[$r->type] ?? 0) + 1;
        }

        return $counts;
    }

    /** Nombre d'entrées du catalogue EasyVista d'origine remplacées par le parcours « accès ». */
    public function accessEntriesCount(): int
    {
        $n = 0;
        foreach ($this->accessResources() as $r) {
            $n += \count($r->allowedActions());
        }

        return $n;
    }

    /** @return array<string, array{libelle: string, description: string, icone: string}> */
    public function families(): array
    {
        return $this->families;
    }

    /** @return array{libelle: string, description: string, icone: string}|null */
    public function family(string $key): ?array
    {
        return $this->families[$key] ?? null;
    }

    /** @return array<string, string> */
    public function accessTypes(): array
    {
        return $this->accessTypes;
    }

    /** @return array<string, array{libelle: string, filtre?: string, famille?: string}> */
    public function incidentCategories(): array
    {
        return $this->incidentCategories;
    }

    private function load(): void
    {
        if (null !== $this->incidents) {
            return;
        }
        if (!is_file($this->catalogueFile)) {
            throw new \RuntimeException(sprintf('Catalogue introuvable : %s (lancez « php bin/console calipso:catalogue:import »).', $this->catalogueFile));
        }
        $data = json_decode((string) file_get_contents($this->catalogueFile), true, 512, \JSON_THROW_ON_ERROR);

        $this->incidents = [];
        foreach ($data['inc'] ?? [] as $row) {
            $subject = new IncidentSubject($row['n'], $row['p'] ?? '', $row['f'] ?? 'autre');
            $subject = new IncidentSubject($subject->name, $subject->path, $subject->family, $this->categoriesOf($subject));
            $this->incidents[] = $subject;
            $this->incidentsByKey[$subject->key()] = $subject;
            $this->incidentsByName[$subject->name] = $subject;
        }
        foreach ($data['svc'] ?? [] as $row) {
            $this->services[$row['t']] = new ServiceItem(
                $row['t'],
                $row['f'] ?? 'proj',
                $row['d'] ?? '',
                $row['g'] ?? '',
                (int) ($row['q'] ?? 0),
                $row['c'] ?? null,
            );
        }
        foreach ($data['acc'] ?? [] as $row) {
            $titles = [];
            foreach ($row['ti'] ?? [] as $action => $title) {
                $titles[(int) $action] = $title;
            }
            $this->access[$row['r']] = new AccessResource($row['r'], $row['t'] ?? 'app', (int) $row['a'], $titles);
        }
    }

    /** @return list<string> */
    private function categoriesOf(IncidentSubject $s): array
    {
        $cats = [];
        foreach ($this->incidentCategories as $key => $def) {
            if (isset($def['famille']) && $s->family === $def['famille']) {
                $cats[] = $key;
            } elseif (isset($def['filtre']) && preg_match('#'.$def['filtre'].'#u', $s->path.'/'.$s->name)) {
                $cats[] = $key;
            }
        }

        return $cats;
    }
}
