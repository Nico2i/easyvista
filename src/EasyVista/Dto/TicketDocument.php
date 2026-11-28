<?php

namespace App\EasyVista\Dto;

/** Pièce jointe d'un incident ou d'une demande (GET /requests/{rfc}/documents). */
final class TicketDocument
{
    public function __construct(
        public readonly string $id,
        public readonly string $filename,
    ) {
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->filename, \PATHINFO_EXTENSION));
    }
}
