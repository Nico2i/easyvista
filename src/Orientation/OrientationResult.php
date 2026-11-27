<?php

namespace App\Orientation;

use App\Catalog\Model\AccessResource;
use App\Catalog\Model\IncidentSubject;
use App\Catalog\Model\ServiceItem;

/** Résultat de l'orientation d'une phrase d'agent vers le catalogue. */
final class OrientationResult
{
    public const PROBLEM = 'p';
    public const NEED = 'd';

    /**
     * @param list<IncidentSubject> $incidents
     * @param list<ServiceItem>     $services
     * @param list<AccessResource>  $access
     * @param list<string>          $families    clés de rubriques
     * @param list<string>          $accessTypes clés de types d'accès
     * @param bool                  $exactService le premier service correspond à tous les mots de la phrase
     * @param bool                  $accessFirst  une ressource d'accès correspond nettement, sans service exact
     * @param string|null           $mode         lecture de la phrase : PROBLEM, NEED ou null si ambiguë
     */
    public function __construct(
        public readonly string $query,
        public readonly bool $soundsLikeProblem,
        public readonly bool $soundsLikeNeed,
        public readonly array $incidents,
        public readonly array $services,
        public readonly array $access,
        public readonly array $families,
        public readonly array $accessTypes,
        public readonly bool $exactService,
        public readonly bool $accessFirst,
        public readonly ?string $mode,
    ) {
    }

    public function hasProblems(): bool
    {
        return [] !== $this->incidents;
    }

    public function hasRequests(): bool
    {
        return [] !== $this->services || [] !== $this->access || [] !== $this->families || [] !== $this->accessTypes;
    }

    public function isEmpty(): bool
    {
        return !$this->hasProblems() && !$this->hasRequests();
    }

    /** Faut-il demander à l'agent si « ça ne fonctionne plus » ou s'il a « un nouveau besoin » ? */
    public function needsClarification(): bool
    {
        return $this->hasProblems() && $this->hasRequests();
    }

    /**
     * Ordre d'affichage des groupes de résultats.
     *
     * @return list<string> parmi 'incidents', 'requests', 'access'
     */
    public function groupOrder(?string $mode): array
    {
        $requests = $this->accessFirst ? ['access', 'requests'] : ['requests', 'access'];

        return self::NEED === $mode ? [...$requests, 'incidents'] : ['incidents', ...$requests];
    }

    /**
     * Ordre des lignes du groupe « Faire une demande ».
     *
     * @return list<string> parmi 'services', 'types', 'families'
     */
    public function requestRowsOrder(): array
    {
        return $this->exactService ? ['services', 'types', 'families'] : ['types', 'families', 'services'];
    }

    /**
     * Premier résultat proposé (page « Comment ça marche »).
     *
     * @param array<string, array{libelle: string}> $familyLabels
     * @param array<string, string>                 $accessTypeLabels
     *
     * @return array{kind: string, label: string}
     */
    public function firstSuggestion(string $incidentPrefix, array $familyLabels, array $accessTypeLabels): array
    {
        $request = function () use ($familyLabels, $accessTypeLabels): ?array {
            if ($this->exactService) {
                return ['kind' => 'DEMANDE', 'label' => $this->services[0]->title];
            }
            if ($this->accessFirst && isset($this->access[0])) {
                return ['kind' => 'DEMANDE', 'label' => $this->access[0]->serviceTitle(AccessResource::GRANT)];
            }
            if (isset($this->accessTypes[0])) {
                return ['kind' => 'Parcours accès', 'label' => $accessTypeLabels[$this->accessTypes[0]] ?? $this->accessTypes[0]];
            }
            if (isset($this->families[0])) {
                return ['kind' => 'Rubrique', 'label' => $familyLabels[$this->families[0]]['libelle'] ?? $this->families[0]];
            }
            if (isset($this->services[0])) {
                return ['kind' => 'DEMANDE', 'label' => $this->services[0]->title];
            }
            if (isset($this->access[0])) {
                return ['kind' => 'DEMANDE', 'label' => $this->access[0]->serviceTitle(AccessResource::GRANT)];
            }

            return null;
        };
        $problem = fn (): ?array => isset($this->incidents[0])
            ? ['kind' => 'INCIDENT', 'label' => $this->incidents[0]->catalogPath($incidentPrefix)]
            : null;

        $first = self::NEED === $this->mode ? ($request() ?? $problem()) : ($problem() ?? $request());

        return $first ?? ['kind' => 'DEMANDE', 'label' => 'Demande libre'];
    }
}
