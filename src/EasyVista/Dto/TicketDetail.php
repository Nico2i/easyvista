<?php

namespace App\EasyVista\Dto;

/** Détail d'un incident ou d'une demande (GET /requests/{rfc}). */
final class TicketDetail
{
    public function __construct(
        public readonly Ticket $ticket,
        /** REQUEST_ID : identifiant interne, sert à retrouver les actions */
        public readonly string $requestId,
        public readonly string $description,
        public readonly ?Employee $recipient,
        public readonly ?Employee $requestor,
        public readonly string $locationPath = '',
        public readonly string $departmentPath = '',
        public readonly ?\DateTimeImmutable $dueAt = null,
        public readonly string $requestorPhone = '',
    ) {
    }

    /**
     * L'agent est-il demandeur ou bénéficiaire ? Un agent ne doit voir que ses propres demandes.
     */
    public function concerns(Employee $employee): bool
    {
        foreach ([$this->recipient, $this->requestor] as $person) {
            if (null === $person) {
                continue;
            }
            if ('' !== $person->id && $person->id === $employee->id) {
                return true;
            }
            if ('' !== $person->email && 0 === strcasecmp($person->email, $employee->email)) {
                return true;
            }
        }

        return false;
    }
}
