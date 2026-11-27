<?php

namespace App\Service;

/** Résumé d'un envoi réussi, affiché sur la page de confirmation. */
final class SubmittedTicket
{
    public function __construct(
        public readonly string $number,
        /** 'p' signalement, 'd' demande */
        public readonly string $kind,
        public readonly string $label,
        public readonly string $validator = '',
        /** La pièce jointe n'a pas pu être transmise */
        public readonly bool $attachmentFailed = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['number'] ?? ''),
            (string) ($data['kind'] ?? 'd'),
            (string) ($data['label'] ?? ''),
            (string) ($data['validator'] ?? ''),
            (bool) ($data['attachmentFailed'] ?? false),
        );
    }
}
