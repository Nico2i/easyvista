<?php

namespace App\Orientation;

use App\Catalog\Catalog;
use App\Catalog\Model\AccessResource;
use App\Catalog\Model\IncidentSubject;
use App\Catalog\Model\ServiceItem;
use App\Text\TextNormalizer;

/**
 * Oriente une phrase d'agent (« mon imprimante n'imprime plus », « un stagiaire arrive lundi »)
 * vers les sujets d'incident, services et accès du catalogue, sans lui demander de choisir
 * entre incident et demande.
 *
 * Trois mécanismes :
 *  1. le dictionnaire de besoins (les mots reconnus sont « consommés ») ;
 *  2. le ton de la phrase (dysfonctionnement ou nouveau besoin) ;
 *  3. la correspondance des mots restants avec les libellés du catalogue.
 */
final class Orienter
{
    private const MAX_INCIDENTS = 5;
    private const MAX_SERVICES = 7;
    private const MAX_ACCESS = 6;
    private const MAX_FAMILIES = 2;
    private const MAX_ACCESS_TYPES = 2;
    private const EXACT = 25;

    /** @var array{inc: list<array{0: IncidentSubject, 1: list<string>}>, svc: list<array{0: ServiceItem, 1: list<string>}>, acc: list<array{0: AccessResource, 1: list<string>}>}|null */
    private ?array $index = null;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly NeedDictionary $dictionary,
    ) {
    }

    public function orient(string $query): OrientationResult
    {
        $n = ' '.TextNormalizer::norm($query).' ';
        $isProb = $this->dictionary->soundsLikeProblem($n);
        $isNeed = $this->dictionary->soundsLikeNeed($n);

        /** @var array<string, array<string, array{obj: mixed, score: float}>> $found */
        $found = ['i' => [], 's' => [], 'a' => [], 'f' => [], 'at' => []];
        $add = static function (string $kind, string $key, mixed $obj, float $score) use (&$found): void {
            if (!isset($found[$kind][$key]) || $found[$kind][$key]['score'] < $score) {
                $found[$kind][$key] = ['obj' => $obj, 'score' => $score];
            }
        };

        // 1. Dictionnaire de besoins : les mots reconnus sont « consommés »
        $rest = $n;
        foreach ($this->dictionary->entries as $entry) {
            $keys = array_values(array_filter($entry['mots'], static fn (string $k) => str_contains($n, ' '.$k)));
            if (!$keys) {
                continue;
            }
            foreach ($keys as $k) {
                $rest = preg_replace('/ '.preg_quote($k, '/').'\w*/', ' ', $rest) ?? $rest;
            }
            foreach ($entry['cibles'] as $ix => $ref) {
                $parts = explode(':', $ref, 2);
                $type = $parts[0];
                $value = $parts[1] ?? '';
                $score = 20 - $ix + $entry['bonus'];
                switch ($type) {
                    case 'i':
                        if ($inc = $this->catalog->incidentByName($value)) {
                            $add('i', $value, $inc, $score);
                        }
                        break;
                    case 's':
                        if ($svc = $this->catalog->service($value)) {
                            $add('s', $value, $svc, $score);
                        }
                        break;
                    case 'f':
                        $add('f', $value, $value, $score);
                        break;
                    case 'a':
                        $add('at', $value, $value, $score);
                        break;
                }
            }
        }

        $tokens = array_values(array_filter(
            explode(' ', trim($rest)),
            fn (string $t) => '' !== $t && \strlen($t) > 1 && !$this->dictionary->isStopWord($t) && !$this->dictionary->isGenericWord($t),
        ));

        // 2. Correspondance avec les libellés du catalogue
        $accStrong = false;
        if ($tokens) {
            $count = \count($tokens);
            $need = max(2, $count * 1.5);
            $full = 3 * $count;
            $bonus = static fn (int $s): float => $s >= $full ? self::EXACT : $s;
            $index = $this->index();

            if (!($isNeed && !$isProb)) {
                foreach ($index['inc'] as [$o, $w]) {
                    $s = self::score($tokens, $w);
                    if ($s >= $need || ($s >= 3 && $count <= 2)) {
                        $add('i', $o->name, $o, $bonus($s));
                    }
                }
            }
            foreach ($index['svc'] as [$o, $w]) {
                $s = self::score($tokens, $w);
                if ($s >= $need) {
                    $add('s', $o->title, $o, $bonus($s));
                }
            }
            foreach ($index['acc'] as [$o, $w]) {
                $s = self::score($tokens, $w);
                if ($s >= 3 || ($s >= 2 && 1 === $count)) {
                    $add('a', $o->name, $o, $bonus($s));
                    if ($s >= 3) {
                        $accStrong = true;
                    }
                }
            }
        }

        $top = static function (array $map, int $k): array {
            $values = array_values($map);
            usort($values, static fn ($a, $b) => $b['score'] <=> $a['score']);

            return array_map(static fn ($x) => $x['obj'], \array_slice($values, 0, $k));
        };

        $incidents = $top($found['i'], self::MAX_INCIDENTS);
        $services = $top($found['s'], self::MAX_SERVICES);
        $access = $top($found['a'], self::MAX_ACCESS);
        $families = array_values(array_filter($top($found['f'], self::MAX_FAMILIES), fn ($f) => null !== $this->catalog->family($f)));
        $accessTypes = array_values(array_filter($top($found['at'], self::MAX_ACCESS_TYPES), fn ($t) => isset($this->catalog->accessTypes()[$t])));

        $isExact = static fn (ServiceItem $s): bool => ($found['s'][$s->title]['score'] ?? 0) >= self::EXACT;
        $exactService = [] !== $services && $isExact($services[0]);
        $accessFirst = $accStrong && [] === array_filter($services, $isExact);

        $others = \count($services) + \count($access) + \count($families) + \count($accessTypes);
        $mode = match (true) {
            $isProb && !$isNeed => OrientationResult::PROBLEM,
            !$isProb && $isNeed => OrientationResult::NEED,
            [] !== $incidents && 0 === $others => OrientationResult::PROBLEM,
            default => null,
        };

        return new OrientationResult($query, $isProb, $isNeed, $incidents, $services, $access, $families, $accessTypes, $exactService, $accessFirst, $mode);
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $words
     */
    private static function score(array $tokens, array $words): int
    {
        $score = 0;
        foreach ($tokens as $t) {
            $best = 0;
            $len = \strlen($t);
            foreach ($words as $x) {
                if ($x === $t) {
                    $best = 3;
                    break;
                }
                if ($len >= 3 && str_starts_with($x, $t)) {
                    $best = max($best, 2);
                } elseif ($len >= 4 && str_contains($x, $t)) {
                    $best = max($best, 1);
                }
            }
            $score += $best;
        }

        return $score;
    }

    private function index(): array
    {
        if (null === $this->index) {
            $this->index = ['inc' => [], 'svc' => [], 'acc' => []];
            foreach ($this->catalog->incidents() as $o) {
                $parts = explode('/', $o->path);
                $this->index['inc'][] = [$o, TextNormalizer::words($o->name.' '.end($parts))];
            }
            foreach ($this->catalog->services() as $o) {
                $this->index['svc'][] = [$o, TextNormalizer::words($o->title)];
            }
            foreach ($this->catalog->accessResources() as $o) {
                $this->index['acc'][] = [$o, TextNormalizer::words($o->name)];
            }
        }

        return $this->index;
    }
}
