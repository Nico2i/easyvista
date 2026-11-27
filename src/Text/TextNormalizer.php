<?php

namespace App\Text;

/**
 * Normalisation des textes saisis par les agents et des libellés du catalogue :
 * minuscules, sans accents, ponctuation remplacée par des espaces.
 */
final class TextNormalizer
{
    public static function norm(?string $text): string
    {
        $text = mb_strtolower((string) $text, 'UTF-8');
        $text = \Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text;
        $text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;
        $text = preg_replace('/[^a-z0-9@]+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /** @return list<string> */
    public static function words(?string $text): array
    {
        $norm = self::norm($text);

        return '' === $norm ? [] : explode(' ', $norm);
    }

    /**
     * Clé de rapprochement d'un intitulé du catalogue (insensible aux accents, à la casse et aux articles),
     * utilisée pour retrouver le code EasyVista d'un service.
     */
    public static function key(?string $text): string
    {
        $words = array_filter(
            self::words($text),
            static fn (string $w): bool => !\in_array($w, ['a', 'au', 'aux', 'l', 'la', 'le', 'les', 'd', 'de', 'du', 'des'], true),
        );

        return implode(' ', $words);
    }

    /** Tous les mots de la recherche sont-ils présents dans le texte ? */
    public static function containsAll(string $haystack, string $query): bool
    {
        $haystack = self::norm($haystack);
        foreach (self::words($query) as $word) {
            if (!str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }
}
