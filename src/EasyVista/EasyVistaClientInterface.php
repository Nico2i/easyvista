<?php

namespace App\EasyVista;

use App\EasyVista\Dto\Employee;
use App\EasyVista\Dto\NewTicket;
use App\EasyVista\Dto\Ticket;
use App\EasyVista\Dto\TicketDetail;
use App\EasyVista\Dto\TicketDocument;
use App\EasyVista\Dto\TicketEvent;
use App\EasyVista\Exception\EasyVistaException;

/**
 * Accès à EasyVista Service Manager.
 *
 * Deux implémentations, choisies par EASYVISTA_MODE :
 *  - HttpEasyVistaClient (api) : API REST v1 (voir docs/easyvista-swagger.json) ;
 *  - MockEasyVistaClient (mock) : données de démonstration, rien n'est envoyé.
 *
 * Toutes les méthodes peuvent lever une EasyVistaException.
 */
interface EasyVistaClientInterface
{
    /** Vrai si le client simule EasyVista (aucun appel réel). */
    public function isMock(): bool;

    /**
     * Teste, sans rien créer, les droits du compte API sur les fonctions de lecture utilisées par le portail.
     * Les profils EasyVista peuvent autoriser certains endpoints et pas d'autres (HTTP 403).
     *
     * @return list<array{endpoint: string, usage: string, ok: bool, detail: string}>
     */
    public function checkAccess(): array;

    /** @throws EasyVistaException */
    public function findEmployeeByLogin(string $login): ?Employee;

    /**
     * Recherche de collègues par nom (bénéficiaire d'une demande).
     *
     * @return list<Employee>
     */
    public function searchEmployees(string $query, int $max = 10): array;

    /**
     * Crée un incident ou une demande de service.
     *
     * @return string numéro EasyVista (RFC_NUMBER), ex. I261104_000034
     */
    public function createTicket(NewTicket $ticket): string;

    /** Joint un fichier à un incident ou une demande. */
    public function attachDocument(string $number, string $filename, string $content): void;

    /**
     * Incidents et demandes de l'agent, du plus récent au plus ancien.
     *
     * @return list<Ticket>
     */
    public function listTickets(Employee $employee): array;

    public function getTicket(string $number): ?Ticket;

    /** Détail complet, description comprise (GET /requests/{rfc}). */
    public function getTicketDetail(string $number): ?TicketDetail;

    /**
     * Actions de l'incident ou de la demande, de la plus ancienne à la plus récente (GET /actions).
     *
     * @return list<TicketEvent>
     */
    public function listTicketEvents(TicketDetail $detail): array;

    /** @return list<TicketDocument> */
    public function listDocuments(string $number): array;

    /**
     * Contenu d'une pièce jointe.
     *
     * @return array{filename: string, content: string, contentType: string}
     */
    public function downloadDocument(string $number, string $documentId): array;

    /** Ajoute un message à destination des intervenants. */
    public function addMessage(string $number, string $message, Employee $author): void;

    /**
     * Code catalogue (SD_CATALOG.CODE) d'un service ou d'un sujet d'incident, par son intitulé ou son chemin.
     */
    public function findCatalogCode(string $title, ?string $path = null): ?string;

    /**
     * Tout le catalogue : code, intitulé et chemin de chaque entrée (synchronisation des codes).
     *
     * @return iterable<array{code: string, title: string, path: string}>
     */
    public function listCatalogEntries(): iterable;
}
