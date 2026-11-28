<?php

namespace App\EasyVista\Dto;

/** Action d'un incident ou d'une demande (GET /actions), affichée dans l'historique. */
final class TicketEvent
{
    public function __construct(
        public readonly string $id,
        /** Libellé de l'action (ACTION_LABEL_xx) */
        public readonly string $label,
        /** Type d'action EasyVista (ACTION_TYPE.NAME_xx) */
        public readonly string $type,
        public readonly ?\DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $endedAt,
        public readonly string $authorName = '',
        public readonly string $authorId = '',
        /** Commentaire ou résolution, en texte brut */
        public readonly string $comment = '',
    ) {
    }

    public function isOpen(): bool
    {
        return null === $this->endedAt;
    }
}
