<?php

namespace App\Controller;

use App\EasyVista\Dto\Ticket;
use App\EasyVista\Dto\TicketDetail;
use App\EasyVista\EasyVistaClientInterface;
use App\EasyVista\Exception\EasyVistaException;
use App\Security\PortalUser;
use App\Service\Counters;
use App\Service\TicketSubmitter;
use App\Service\TicketView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Mes demandes » : liste des problèmes signalés et des demandes, et panneau de détail
 * (description, informations, pièces jointes, historique des actions).
 */
final class TicketController extends AbstractController
{
    private const FILTERS = ['en-cours', 'terminees', 'toutes'];

    /** @param array<string, mixed> $easyvistaOptions */
    public function __construct(
        private readonly EasyVistaClientInterface $easyVista,
        private readonly TicketView $view,
        #[Autowire('%calipso.easyvista%')] private readonly array $easyvistaOptions,
    ) {
    }

    #[Route('/mes-demandes', name: 'app_my_requests', methods: ['GET'])]
    public function list(Request $request): Response
    {
        // Ancienne forme d'URL (?rfc=…) : on ouvre directement le panneau
        if ('' !== ($rfc = (string) $request->query->get('rfc', ''))) {
            return $this->redirectToRoute('app_ticket_show', ['number' => $rfc, 'statut' => $request->query->get('statut')]);
        }

        return $this->render('tickets/list.html.twig', $this->listContext($request));
    }

    /** Panneau de détail : fragment HTML (appel JavaScript) ou page complète avec le panneau ouvert. */
    #[Route('/mes-demandes/{number}', name: 'app_ticket_show', requirements: ['number' => '[A-Za-z0-9_\-]+'], methods: ['GET'])]
    public function show(string $number, Request $request): Response
    {
        $error = null;
        $drawer = null;
        try {
            $detail = $this->detailFor($number);
            $drawer = $this->drawerContext($detail);
        } catch (EasyVistaException $e) {
            if (404 === $e->getStatusCode()) {
                throw $this->createNotFoundException();
            }
            $error = $e->getMessage();
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('tickets/_drawer.html.twig', ['d' => $drawer, 'drawerError' => $error, 'filter' => $this->filter($request), 'number' => $number]);
        }

        return $this->render('tickets/list.html.twig', [
            'd' => $drawer,
            'drawerError' => $error,
            'number' => $number,
        ] + $this->listContext($request));
    }

    #[Route('/mes-demandes/{number}/message', name: 'app_ticket_message', methods: ['POST'])]
    public function message(string $number, Request $request): Response
    {
        $message = trim((string) $request->request->get('message', ''));

        if (!$this->isCsrfTokenValid('message'.$number, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire a expiré : merci de recommencer.');
        } elseif ('' === $message) {
            $this->addFlash('error', 'Votre message est vide.');
        } else {
            try {
                $this->detailFor($number);
                $this->easyVista->addMessage($number, $message, $this->portalUser()->employee);
                $this->addFlash('success', 'Votre message a été transmis aux intervenants.');
            } catch (EasyVistaException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_ticket_show', ['number' => $number, 'statut' => $request->request->get('statut')]);
    }

    #[Route('/mes-demandes/{number}/documents', name: 'app_ticket_attach', methods: ['POST'])]
    public function attach(string $number, Request $request, TicketSubmitter $submitter, Counters $counters): Response
    {
        $file = $request->files->get('piece_jointe');
        if (!$this->isCsrfTokenValid('attach'.$number, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire a expiré : merci de recommencer.');
        } elseif (!$file instanceof UploadedFile || \UPLOAD_ERR_NO_FILE === $file->getError()) {
            $this->addFlash('error', 'Choisissez un fichier à joindre.');
        } else {
            try {
                $this->detailFor($number);
                $submitter->attachToTicket($number, $file);
                $this->addFlash('success', sprintf('« %s » a été ajouté à la demande.', $file->getClientOriginalName()));
            } catch (\InvalidArgumentException|EasyVistaException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_ticket_show', ['number' => $number, 'statut' => $request->request->get('statut')]);
    }

    #[Route('/mes-demandes/{number}/documents/{id}', name: 'app_ticket_document', methods: ['GET'])]
    public function document(string $number, string $id): Response
    {
        try {
            $this->detailFor($number);
            $file = $this->easyVista->downloadDocument($number, $id);
        } catch (EasyVistaException $e) {
            if (404 === $e->getStatusCode()) {
                throw $this->createNotFoundException();
            }
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_ticket_show', ['number' => $number]);
        }

        $response = new Response($file['content'], 200, ['Content-Type' => $file['contentType']]);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file['filename'],
            preg_replace('/[^\x20-\x7e]/', '_', $file['filename']) ?: 'document',
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Détail d'une demande de l'agent connecté. Une demande d'un autre agent est traitée comme introuvable.
     *
     * @throws EasyVistaException (404 si introuvable ou non autorisée)
     */
    private function detailFor(string $number): TicketDetail
    {
        $detail = $this->easyVista->getTicketDetail($number);
        if (null === $detail || (!$this->easyVista->isMock() && !$detail->concerns($this->portalUser()->employee))) {
            throw new EasyVistaException('Demande introuvable.', 404);
        }

        return $detail;
    }

    /** @return array<string, mixed> */
    private function drawerContext(TicketDetail $detail): array
    {
        $events = [];
        $historyError = null;
        try {
            $events = $this->easyVista->listTicketEvents($detail);
        } catch (EasyVistaException $e) {
            $historyError = $e->getMessage();
        }
        $documents = [];
        $documentsError = null;
        try {
            $documents = $this->easyVista->listDocuments($detail->ticket->number);
        } catch (EasyVistaException $e) {
            $documentsError = $e->getMessage();
        }

        return [
            'detail' => $detail,
            't' => $detail->ticket,
            'documents' => $documents,
            'documentsError' => $documentsError,
            'historyError' => $historyError,
            'canMessage' => $detail->ticket->isOpen() && ($this->easyVista->isMock() || '' !== (string) ($this->easyvistaOptions['type_action_message'] ?? '')),
        ] + $this->view->build($detail, $events, $this->portalUser()->employee);
    }

    /** @return array<string, mixed> */
    private function listContext(Request $request): array
    {
        $filter = $this->filter($request);
        $error = null;
        $tickets = [];
        try {
            $tickets = $this->easyVista->listTickets($this->portalUser()->employee);
        } catch (EasyVistaException $e) {
            $error = $e->getMessage();
        }

        return [
            'tickets' => array_values(array_filter($tickets, static fn (Ticket $t) => match ($filter) {
                'terminees' => !$t->isOpen(),
                'toutes' => true,
                default => $t->isOpen(),
            })),
            'filter' => $filter,
            'counts' => [
                'en-cours' => \count(array_filter($tickets, static fn (Ticket $t) => $t->isOpen())),
                'terminees' => \count(array_filter($tickets, static fn (Ticket $t) => !$t->isOpen())),
                'toutes' => \count($tickets),
            ],
            'error' => $error,
            'd' => null,
            'drawerError' => null,
            'number' => null,
        ];
    }

    private function filter(Request $request): string
    {
        $filter = (string) ($request->query->get('statut') ?? $request->request->get('statut') ?? 'en-cours');

        return \in_array($filter, self::FILTERS, true) ? $filter : 'en-cours';
    }

    private function portalUser(): PortalUser
    {
        $user = $this->getUser();
        if (!$user instanceof PortalUser) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
