<?php

namespace App\Catalog\Model;

/** Sujet d'incident du catalogue EasyVista (ex. « Incidents/IT/Equipement de proximité/Impression/Imprimante »). */
final class IncidentSubject
{
    /** @param list<string> $categories */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly string $family,
        public readonly array $categories = [],
    ) {
    }

    /** Identifiant stable utilisé dans les URL. */
    public function key(): string
    {
        return '' === $this->path ? $this->name : $this->path.'/'.$this->name;
    }

    /** Chemin complet dans le catalogue EasyVista. */
    public function catalogPath(string $prefix): string
    {
        return implode('/', array_filter([$prefix, $this->path, $this->name], static fn ($p) => '' !== $p));
    }

    /** Libellé lisible par un agent : « Imprimante ne fonctionne pas correctement ». */
    public function problemLabel(): string
    {
        $name = $this->name;
        if (mb_strlen($name) <= 6 || $name === mb_strtoupper($name)) {
            $name = mb_substr($name, 0, 1).mb_strtolower(mb_substr($name, 1));
        }

        return $name.' ne fonctionne pas correctement';
    }

    /** « Equipement de proximité/Impression/Imprimante » devient « Impression › Imprimante ». */
    public function humanPath(bool $withName = true): string
    {
        $p = $withName ? $this->key() : $this->path;
        $p = preg_replace('#^Equipement (de proximité|central)/?#u', '', $p) ?? $p;
        $p = preg_replace('#^Logiciel/?#u', 'Application ', $p) ?? $p;

        return trim(str_replace('/', ' › ', $p));
    }

    public function inCategory(string $category): bool
    {
        return \in_array($category, $this->categories, true);
    }
}
