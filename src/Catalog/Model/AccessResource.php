<?php

namespace App\Catalog\Model;

/**
 * Ressource (application, dossier Q:, boîte partagée…) sur laquelle on peut demander un accès.
 * Les quatre services EasyVista « Demander à accéder à… », « …la modification… », « …la révocation… »
 * et « …un relevé des accès… » sont regroupés dans un seul parcours.
 */
final class AccessResource
{
    public const GRANT = 1;
    public const REVOKE = 2;
    public const MODIFY = 4;
    public const AUDIT = 8;

    public const ACTIONS = [
        self::GRANT => ['verbe' => 'Obtenir', 'libelle' => 'Obtenir un accès', 'aide' => 'Pour moi ou un·e collègue'],
        self::MODIFY => ['verbe' => 'Modifier', 'libelle' => 'Modifier des droits', 'aide' => 'Lecture, écriture…'],
        self::REVOKE => ['verbe' => 'Retirer', 'libelle' => 'Retirer un accès', 'aide' => 'Départ, changement de poste'],
        self::AUDIT => ['verbe' => 'Qui a accès ?', 'libelle' => 'Savoir qui a accès', 'aide' => 'Relevé des personnes habilitées'],
    ];

    /** @param array<int, string> $titles intitulés exacts du catalogue, par action (si connus) */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int $actions,
        public readonly array $titles = [],
    ) {
    }

    public function allows(int $action): bool
    {
        return ($this->actions & $action) === $action;
    }

    /** @return list<int> */
    public function allowedActions(): array
    {
        return array_values(array_filter(array_keys(self::ACTIONS), fn (int $a) => $this->allows($a)));
    }

    /** Intitulé du service EasyVista correspondant à l'action. */
    public function serviceTitle(int $action): string
    {
        return $this->titles[$action] ?? match ($action) {
            self::GRANT => 'Demander à accéder à '.$this->name,
            self::MODIFY => "Demander la modification d'un accès à ".$this->name,
            self::REVOKE => "Demander la révocation d'un accès à ".$this->name,
            self::AUDIT => 'Demander un relevé des accès à '.$this->name,
            default => throw new \InvalidArgumentException('Action inconnue : '.$action),
        };
    }
}
