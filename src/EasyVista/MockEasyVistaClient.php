<?php

namespace App\EasyVista;

use App\EasyVista\Dto\Employee;
use App\EasyVista\Dto\NewTicket;
use App\EasyVista\Dto\Ticket;
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
                $t['documents'][] = ['filename' => $filename, 'size' => \strlen($content)];
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

    public function addMessage(string $number, string $message, Employee $author): void
    {
        $file = $this->storageDir.'/messages.log';
        $this->ensureDir();
        file_put_contents($file, sprintf("[%s] %s → %s : %s\n", date('c'), $author->login, $number, $message), \FILE_APPEND);
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
