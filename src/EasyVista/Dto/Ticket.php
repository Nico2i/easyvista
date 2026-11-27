<?php

namespace App\EasyVista\Dto;

/** Incident ou demande lu dans EasyVista (GET /requests). */
final class Ticket
{
    public const STATUS_WAIT = 'wait';
    public const STATUS_RUNNING = 'run';
    public const STATUS_DONE = 'ok';

    public function __construct(
        public readonly string $number,
        public readonly string $label,
        public readonly ?\DateTimeImmutable $submittedAt,
        public readonly string $statusLabel,
        /** STATUS_WAIT, STATUS_RUNNING ou STATUS_DONE */
        public readonly string $status,
        public readonly ?\DateTimeImmutable $closedAt = null,
        public readonly string $catalogPath = '',
        public readonly string $recipientName = '',
    ) {
    }

    /** Les numéros EasyVista commencent par I pour un incident, S pour une demande de service. */
    public function isIncident(): bool
    {
        return str_starts_with(strtoupper($this->number), 'I');
    }

    public function isOpen(): bool
    {
        return self::STATUS_DONE !== $this->status;
    }
}
