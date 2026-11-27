<?php

namespace App\EasyVista\Dto;

/** Incident ou demande de service à créer (POST /requests). */
final class NewTicket
{
    public const INCIDENT = 'p';
    public const REQUEST = 'd';

    public function __construct(
        /** INCIDENT ou REQUEST (sert à l'affichage ; c'est le code catalogue qui décide pour EasyVista) */
        public readonly string $kind,
        public readonly string $catalogCode,
        public readonly string $title,
        public readonly string $description,
        public readonly string $recipientName,
        public readonly string $recipientEmail = '',
        public readonly ?Employee $requestor = null,
        public readonly ?int $urgencyId = null,
    ) {
    }
}
