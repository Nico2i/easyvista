<?php

namespace App\Service;

use App\Catalog\Model\AccessResource;
use App\Catalog\Model\IncidentSubject;
use App\Catalog\Model\ServiceItem;
use App\EasyVista\CatalogCodeResolver;
use App\EasyVista\Dto\NewTicket;
use App\EasyVista\EasyVistaClientInterface;
use App\EasyVista\Exception\EasyVistaException;
use App\Security\PortalUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Transforme ce que l'agent a saisi en incident ou en demande de service EasyVista.
 * L'agent ne choisit jamais lui-même entre les deux : c'est le parcours qui décide.
 */
class TicketSubmitter
{
    public const GENES = [
        'bloquant' => ['libelle' => 'Je ne peux plus travailler', 'aide' => 'Bloquant pour moi ou mon équipe'],
        'gene' => ['libelle' => 'Je suis gêné·e', 'aide' => 'Je peux avancer autrement'],
        'attente' => ['libelle' => 'Ça peut attendre', 'aide' => 'Pas d\'urgence'],
    ];

    private const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'pdf', 'txt', 'log', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'csv', 'msg', 'eml', 'zip'];

    /**
     * @param array<string, mixed> $easyvistaOptions
     * @param array<string, mixed> $portal
     */
    public function __construct(
        private readonly EasyVistaClientInterface $easyVista,
        private readonly CatalogCodeResolver $codes,
        private readonly LoggerInterface $logger,
        private readonly array $easyvistaOptions,
        private readonly array $portal,
    ) {
    }

    /** @throws EasyVistaException|\InvalidArgumentException */
    public function submitIncident(PortalUser $user, ?IncidentSubject $subject, string $description, string $gene, Beneficiary $for, string $phone = '', ?UploadedFile $file = null): SubmittedTicket
    {
        $description = trim($description);
        if ('' === $description) {
            throw new \InvalidArgumentException('Décrivez ce qui se passe.');
        }
        if (!isset(self::GENES[$gene])) {
            $gene = 'attente';
        }
        $this->checkFile($file);

        $lines = [$description, ''];
        $lines[] = 'Gêne exprimée : '.self::GENES[$gene]['libelle'];
        if (!$for->isSelf) {
            $lines[] = 'Signalé par '.$user->name().' pour '.$for->label();
        }
        if ('' !== trim($phone)) {
            $lines[] = 'Numéro pour joindre : '.trim($phone);
        }
        $lines[] = 'Sujet choisi : '.($subject ? $subject->catalogPath($this->codes->incidentPrefix()) : 'à qualifier par la hotline');
        $lines[] = '(Envoyé depuis Calipso)';

        $title = ($subject ? $subject->name.' : ' : '').self::firstLine($description);
        $ticket = new NewTicket(
            NewTicket::INCIDENT,
            $this->codes->forIncident($subject),
            $title,
            implode("\n", $lines),
            $for->name,
            $for->email,
            $user->employee,
            $this->urgency($gene),
        );

        return $this->send($ticket, $subject ? $subject->name : 'Problème à qualifier', '', $file);
    }

    /** @throws EasyVistaException|\InvalidArgumentException */
    public function submitService(PortalUser $user, ServiceItem $service, string $details, int $quantity, Beneficiary $for, ?UploadedFile $file = null): SubmittedTicket
    {
        $this->checkFile($file);
        $lines = [];
        if ('' !== trim($details)) {
            $lines[] = trim($details);
            $lines[] = '';
        }
        if ($service->maxQuantity > 0) {
            $quantity = max(1, min($quantity, $service->maxQuantity));
            $lines[] = 'Quantité : '.$quantity;
        }
        if (!$for->isSelf) {
            $lines[] = 'Demandé par '.$user->name().' pour '.$for->label();
        }
        $lines[] = '(Envoyé depuis Calipso)';

        $ticket = new NewTicket(
            NewTicket::REQUEST,
            $this->codes->forService($service->title, $service->code),
            $service->title,
            implode("\n", $lines),
            $for->name,
            $for->email,
            $user->employee,
        );

        return $this->send($ticket, $service->title, $service->validator, $file);
    }

    /** @throws EasyVistaException|\InvalidArgumentException */
    public function submitAccess(PortalUser $user, AccessResource $resource, int $action, string $why, Beneficiary $for): SubmittedTicket
    {
        if (!$resource->allows($action)) {
            throw new \InvalidArgumentException('Cette action n\'est pas possible sur cette ressource.');
        }
        $title = $resource->serviceTitle($action);
        $lines = [];
        if ('' !== trim($why)) {
            $lines[] = 'Justification : '.trim($why);
            $lines[] = '';
        }
        if (!$for->isSelf) {
            $lines[] = 'Demandé par '.$user->name().' pour '.$for->label();
        }
        $lines[] = '(Envoyé depuis Calipso)';

        $ticket = new NewTicket(
            NewTicket::REQUEST,
            $this->codes->forService($title),
            $title,
            implode("\n", $lines),
            $for->name,
            $for->email,
            $user->employee,
        );

        return $this->send($ticket, $title, '', null);
    }

    /**
     * Ajoute une pièce jointe à une demande existante (panneau de détail).
     *
     * @throws EasyVistaException|\InvalidArgumentException
     */
    public function attachToTicket(string $number, UploadedFile $file): void
    {
        $this->checkFile($file);
        $this->easyVista->attachDocument($number, $file->getClientOriginalName(), (string) file_get_contents($file->getPathname()));
    }

    public function maxFileSize(): int
    {
        return (int) ($this->portal['pj_max_mo'] ?? 5) * 1024 * 1024;
    }

    private function send(NewTicket $ticket, string $label, string $validator, ?UploadedFile $file): SubmittedTicket
    {
        $number = $this->easyVista->createTicket($ticket);

        $attachmentFailed = false;
        if (null !== $file) {
            try {
                $this->easyVista->attachDocument($number, $file->getClientOriginalName(), (string) file_get_contents($file->getPathname()));
            } catch (EasyVistaException $e) {
                $this->logger->warning('Pièce jointe non transmise pour {number} : {message}', ['number' => $number, 'message' => $e->getMessage()]);
                $attachmentFailed = true;
            }
        }

        return new SubmittedTicket($number, $ticket->kind, $label, $validator, $attachmentFailed);
    }

    private function urgency(string $gene): ?int
    {
        $id = $this->easyvistaOptions['urgences'][$gene] ?? null;

        return null === $id || '' === $id ? null : (int) $id;
    }

    private function checkFile(?UploadedFile $file): void
    {
        if (null === $file) {
            return;
        }
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Le fichier joint n\'a pas pu être reçu ('.$file->getErrorMessage().').');
        }
        if ($file->getSize() > $this->maxFileSize()) {
            throw new \InvalidArgumentException(sprintf('Le fichier joint dépasse %d Mo.', $this->maxFileSize() / 1024 / 1024));
        }
        if (!\in_array(strtolower($file->getClientOriginalExtension()), self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Ce type de fichier n\'est pas accepté (images, PDF, documents bureautiques, texte).');
        }
    }

    private static function firstLine(string $text): string
    {
        $line = trim(strtok($text, "\n") ?: $text);

        return mb_strlen($line) > 80 ? mb_substr($line, 0, 79).'…' : $line;
    }
}
