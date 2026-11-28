<?php

namespace App\EasyVista;

use App\EasyVista\Dto\Employee;
use App\EasyVista\Dto\NewTicket;
use App\EasyVista\Dto\Ticket;
use App\EasyVista\Dto\TicketDetail;
use App\EasyVista\Dto\TicketDocument;
use App\EasyVista\Dto\TicketEvent;
use App\EasyVista\Exception\EasyVistaException;
use App\Text\TextNormalizer;

/**
 * Faux EasyVista pour la démonstration et les tests (EASYVISTA_MODE=mock).
 * Rien n'est envoyé : les demandes créées sont conservées dans var/mock/tickets.json.
 */
class MockEasyVistaClient implements EasyVistaClientInterface
{
    private const EMPLOYEES = [
        ['1001', 'agent.demo', 'MARTIN Camille', 'camille.martin@example.org', '02 96 00 00 01', 'DSN/Pôle usages', 'Saint-Brieuc/Siège'],
        ['1002', 'cdurand', 'DURAND Claire', 'claire.durand@example.org', '02 96 00 00 02', 'DRH/Carrières', 'Saint-Brieuc/Siège'],
        ['1003', 'ltanguy', 'TANGUY Loïc', 'loic.tanguy@example.org', '02 96 00 00 03', 'DSN/Infrastructures', 'Saint-Brieuc/Siège'],
        ['1004', 'mlebras', 'LE BRAS Morgane', 'morgane.lebras@example.org', '02 96 00 00 04', 'Routes/Agence de Lannion', 'Lannion'],
        ['1005', 'yguillou', 'GUILLOU Yann', 'yann.guillou@example.org', '02 96 00 00 05', 'Solidarités/MDD Guingamp', 'Guingamp'],
    ];

    private const SEED_TICKETS = [
        ['S261103_000101', '2026-11-03 09:12', "Demander le référencement d'un nouvel espace GECO", 'En attente de validation', Ticket::STATUS_WAIT],
        ['S261102_000052', '2026-11-02 14:40', "Demander le référencement d'un nouvel espace GECO", 'En attente de validation', Ticket::STATUS_WAIT],
        ['I261030_000018', '2026-10-30 08:55', 'Imprimante', 'En cours de traitement', Ticket::STATUS_RUNNING],
        ['S261028_000033', '2026-10-28 11:03', 'Demander un certificat serveur SSL RGS', 'En cours de traitement', Ticket::STATUS_RUNNING],
        ['S261008_000052', '2026-10-08 16:21', 'Modifier une réservation', 'En cours de traitement', Ticket::STATUS_RUNNING],
        ['S260922_000059', '2026-09-22 10:00', 'Demander une dérogation Firewall', 'En cours de traitement', Ticket::STATUS_RUNNING],
        ['I260915_000007', '2026-09-15 13:30', 'OUTLOOK', 'Clôturé', Ticket::STATUS_DONE],
        ['S260901_000044', '2026-09-01 09:45', 'Demander un casque audio pour ordinateur', 'Clôturé', Ticket::STATUS_DONE],
    ];

    private const SEED_DESCRIPTIONS = [
        'I261030_000018' => "L'imprimante multifonction du 2e étage affiche « bourrage » en permanence, même après avoir retiré le papier.\n\nGêne exprimée : Je suis gêné·e",
        'S261103_000101' => "Création d'un espace GECO pour le projet « Archives numériques 2027 ».\nMembres : 6 agents du pôle usages.",
        'I260915_000007' => "Outlook se ferme tout seul au démarrage depuis ce matin.\n\nGêne exprimée : Je ne peux plus travailler",
    ];

    public function __construct(private readonly string $storageDir)
    {
    }

    public function isMock(): bool
    {
        return true;
    }

    public function checkAccess(): array
    {
        return [['endpoint' => '(simulation)', 'usage' => 'Aucune connexion à EasyVista', 'ok' => true, 'detail' => 'mode démonstration']];
    }

    public function findEmployeeByLogin(string $login): ?Employee
    {
        foreach (self::EMPLOYEES as $e) {
            if (0 === strcasecmp($e[1], $login)) {
                return new Employee(...$e);
            }
        }

        // En démonstration, tout login inconnu devient l'agent de démonstration
        return new Employee('1001', $login, 'MARTIN Camille', 'camille.martin@example.org', '02 96 00 00 01', 'DSN/Pôle usages', 'Saint-Brieuc/Siège');
    }

    public function searchEmployees(string $query, int $max = 10): array
    {
        $result = [];
        foreach (self::EMPLOYEES as $e) {
            if ('' !== trim($query) && TextNormalizer::containsAll($e[2].' '.$e[3], $query)) {
                $result[] = new Employee(...$e);
            }
        }

        return \array_slice($result, 0, $max);
    }

    public function createTicket(NewTicket $ticket): string
    {
        $tickets = $this->stored();
        $prefix = (NewTicket::INCIDENT === $ticket->kind ? 'I' : 'S').date('ymd');
        $number = sprintf('%s_%06d', $prefix, 100 + \count($tickets) + 1);
        $tickets[] = [
            'number' => $number,
            'date' => date('Y-m-d H:i'),
            'label' => $ticket->title,
            'code' => $ticket->catalogCode,
            'recipient' => $ticket->recipientName,
            'requestor' => $ticket->requestor?->login,
            'urgency' => $ticket->urgencyId,
            'description' => $ticket->description,
            'documents' => [],
        ];
        $this->save($tickets);

        return $number;
    }

    public function attachDocument(string $number, string $filename, string $content): void
    {
        $tickets = $this->stored();
        foreach ($tickets as &$t) {
            if ($t['number'] === $number) {
                $index = \count($t['documents'] ?? []);
                $t['documents'][] = ['filename' => $filename, 'size' => \strlen($content)];
                $this->ensureDir();
                if (!is_dir($this->storageDir.'/files')) {
                    mkdir($this->storageDir.'/files', 0777, true);
                }
                file_put_contents($this->storageDir.'/files/'.md5($number.'/'.$index), $content);
            }
        }
        unset($t);
        $this->save($tickets);
    }

    public function listTickets(Employee $employee): array
    {
        $created = array_map(fn (array $t) => new Ticket(
            $t['number'],
            $t['label'],
            new \DateTimeImmutable($t['date']),
            str_starts_with($t['number'], 'I') ? 'En cours de traitement' : 'En attente de validation',
            str_starts_with($t['number'], 'I') ? Ticket::STATUS_RUNNING : Ticket::STATUS_WAIT,
            null,
            '',
            (string) ($t['recipient'] ?? ''),
        ), array_reverse($this->stored()));

        $seed = array_map(static fn (array $t) => new Ticket(
            $t[0],
            $t[2],
            new \DateTimeImmutable($t[1]),
            $t[3],
            $t[4],
            Ticket::STATUS_DONE === $t[4] ? (new \DateTimeImmutable($t[1]))->modify('+2 days') : null,
            '',
            $employee->name,
        ), self::SEED_TICKETS);

        return [...$created, ...$seed];
    }

    public function getTicket(string $number): ?Ticket
    {
        foreach ($this->listTickets($this->findEmployeeByLogin('agent.demo')) as $t) {
            if ($t->number === $number) {
                return $t;
            }
        }

        return null;
    }

    public function getTicketDetail(string $number): ?TicketDetail
    {
        $ticket = $this->getTicket($number);
        if (null === $ticket) {
            return null;
        }
        $demo = new Employee(...self::EMPLOYEES[0]);
        $stored = $this->storedTicket($number);
        $description = (string) ($stored['description'] ?? self::SEED_DESCRIPTIONS[$number] ?? 'Demande envoyée depuis Calipso.');
        $recipient = $stored && '' !== (string) ($stored['recipient'] ?? '') && $stored['recipient'] !== $demo->name
            ? new Employee('', '', (string) $stored['recipient'])
            : $demo;

        return new TicketDetail(
            $ticket,
            'mock-'.$number,
            $description,
            $recipient,
            $demo,
            'Saint-Brieuc/Siège/Bâtiment A',
            $demo->departmentPath,
            $ticket->submittedAt?->modify($ticket->isIncident() ? '+1 day' : '+14 days'),
            $demo->phone,
        );
    }

    public function listTicketEvents(TicketDetail $detail): array
    {
        $t = $detail->ticket;
        $at = $t->submittedAt ?? new \DateTimeImmutable();
        $ev = static fn (string $id, string $type, string $label, string $start, ?string $end, string $who = '', string $comment = '', string $whoId = '') => new TicketEvent(
            $id, $label, $type, $at->modify($start), null === $end ? null : $at->modify($end), $who, $whoId, $comment,
        );

        $events = [];
        if ($t->isIncident()) {
            $events[] = $ev('1', 'Prise en compte', 'Prise en compte par la hotline', '+2 minutes', '+2 minutes', 'Hotline DSN');
            $events[] = $ev('2', 'Affectation', 'Affectation au groupe Support poste de travail', '+25 minutes', '+25 minutes', 'Hotline DSN');
            if ('I261030_000018' === $t->number) {
                $events[] = $ev('3', 'Commentaire', 'Message de l\'intervenant', '+47 minutes', '+47 minutes', 'TANGUY Loïc', 'Bonjour, je passe voir la machine ce matin vers 11h. Pouvez-vous laisser le capot fermé d\'ici là ?', '1003');
            }
            if (Ticket::STATUS_DONE === $t->status) {
                $events[] = $ev('4', 'Intervention', 'Prise en main à distance', '+40 minutes', '+3 hours', 'GUILLOU Yann', 'Prise en main effectuée, je reconstruis votre profil. Merci de ne pas redémarrer le poste.', '1005');
                $events[] = $ev('5', 'Résolution', 'Résolu', '+20 hours', '+20 hours', 'GUILLOU Yann', 'Profil reconstruit et cache des compléments supprimé. L\'application redémarre normalement.', '1005');
                $events[] = $ev('6', 'Clôture', 'Clôture automatique', '+21 hours', '+21 hours');
            } else {
                $events[] = $ev('7', 'Intervention', 'Intervention sur site', '+2 hours', null, 'TANGUY Loïc', '', '1003');
            }
        } else {
            $events[] = $ev('11', 'Validation', 'Validation par le responsable hiérarchique', '+1 minute', Ticket::STATUS_WAIT === $t->status ? null : '+1 day', 'LE BRAS Morgane', '', '1004');
            if (Ticket::STATUS_WAIT !== $t->status) {
                $events[] = $ev('12', 'Affectation', 'Affectation à DSN Assistance', '+1 day 10 minutes', '+1 day 10 minutes', 'DSN Assistance');
                $events[] = Ticket::STATUS_DONE === $t->status
                    ? $ev('13', 'Clôture', 'Livrée et clôturée', '+3 days', '+3 days', 'DURAND Claire', 'Matériel remis en main propre.', '1002')
                    : $ev('14', 'Traitement', 'Traitement en cours', '+1 day 2 hours', null, 'DURAND Claire', '', '1002');
            }
        }

        foreach ($this->messages()[$t->number] ?? [] as $i => $m) {
            $date = new \DateTimeImmutable($m['date']);
            $events[] = new TicketEvent('m'.$i, 'Message de l\'agent', 'Commentaire', $date, $date, (string) $m['author_name'], (string) $m['author_id'], (string) $m['text']);
        }
        usort($events, static fn (TicketEvent $a, TicketEvent $b) => ($a->startedAt?->getTimestamp() ?? 0) <=> ($b->startedAt?->getTimestamp() ?? 0));

        return $events;
    }

    public function listDocuments(string $number): array
    {
        $docs = [];
        foreach ($this->storedTicket($number)['documents'] ?? [] as $i => $d) {
            $docs[] = new TicketDocument((string) $i, (string) $d['filename']);
        }
        if ('I261030_000018' === $number) {
            $docs[] = new TicketDocument('seed', 'photo-ecran-multifonction.jpg');
        }

        return $docs;
    }

    public function downloadDocument(string $number, string $documentId): array
    {
        foreach ($this->listDocuments($number) as $d) {
            if ($d->id === $documentId) {
                $file = $this->storageDir.'/files/'.md5($number.'/'.$documentId);
                $content = is_file($file) ? (string) file_get_contents($file) : "Fichier de démonstration : {$d->filename}\n";

                return ['filename' => $d->filename, 'content' => $content, 'contentType' => is_file($file) ? 'application/octet-stream' : 'text/plain; charset=UTF-8'];
            }
        }
        throw new EasyVistaException('Élément introuvable dans EasyVista.', 404);
    }

    public function addMessage(string $number, string $message, Employee $author): void
    {
        $messages = $this->messages();
        $messages[$number][] = ['date' => date('Y-m-d H:i:s'), 'author_id' => $author->id, 'author_name' => $author->name, 'text' => $message];
        $this->ensureDir();
        file_put_contents($this->storageDir.'/messages.json', json_encode($messages, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE), \LOCK_EX);
    }

    /** @return array<string, list<array{date: string, author_id: string, author_name: string, text: string}>> */
    private function messages(): array
    {
        $file = $this->storageDir.'/messages.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return \is_array($data) ? $data : [];
    }

    /** @return array<string, mixed>|null */
    private function storedTicket(string $number): ?array
    {
        foreach ($this->stored() as $t) {
            if ($t['number'] === $number) {
                return $t;
            }
        }

        return null;
    }

    public function findCatalogCode(string $title, ?string $path = null): ?string
    {
        return 'SIMU_'.strtoupper(substr(md5($path ?? $title), 0, 6));
    }

    public function listCatalogEntries(): iterable
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    private function stored(): array
    {
        $file = $this->storageDir.'/tickets.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return \is_array($data) ? array_values($data) : [];
    }

    /** @param list<array<string, mixed>> $tickets */
    private function save(array $tickets): void
    {
        $this->ensureDir();
        file_put_contents($this->storageDir.'/tickets.json', json_encode($tickets, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), \LOCK_EX);
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0777, true);
        }
    }
}
