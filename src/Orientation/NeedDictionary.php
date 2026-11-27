<?php

namespace App\Orientation;

/**
 * Dictionnaire de besoins (config/calipso/besoins.yaml) : formulations courantes des agents
 * associées à des sujets d'incident, des services, des rubriques ou des types d'accès.
 */
final class NeedDictionary
{
    /** @var list<array{mots: list<string>, cibles: list<string>, bonus: int}> */
    public readonly array $entries;
    /** @var array<string, true> */
    private readonly array $stopWords;
    /** @var array<string, true> */
    private readonly array $genericWords;

    /**
     * @param list<array{mots: list<string>, cibles: list<string>, bonus?: int}> $entries
     * @param list<string>                                                      $genericWords
     */
    public function __construct(
        array $entries,
        private readonly string $problemPattern,
        private readonly string $needPattern,
        string $stopWords,
        array $genericWords,
    ) {
        $this->entries = array_map(static fn (array $e) => [
            'mots' => array_values($e['mots']),
            'cibles' => array_values($e['cibles']),
            'bonus' => (int) ($e['bonus'] ?? 0),
        ], $entries);
        $this->stopWords = array_fill_keys(preg_split('/\s+/', trim($stopWords)) ?: [], true);
        $this->genericWords = array_fill_keys($genericWords, true);
    }

    /** La phrase (normalisée, entourée d'espaces) exprime-t-elle un dysfonctionnement ? */
    public function soundsLikeProblem(string $normalized): bool
    {
        return 1 === preg_match('/'.$this->problemPattern.'/', $normalized);
    }

    /** La phrase exprime-t-elle un nouveau besoin ? (« je n'ai plus accès à » n'en est pas un) */
    public function soundsLikeNeed(string $normalized): bool
    {
        $normalized = preg_replace('/(plus|pas|jamais) acces a/', '', $normalized) ?? $normalized;

        return 1 === preg_match('/'.$this->needPattern.'/', $normalized);
    }

    public function isStopWord(string $word): bool
    {
        return isset($this->stopWords[$word]);
    }

    public function isGenericWord(string $word): bool
    {
        return isset($this->genericWords[$word]);
    }
}
