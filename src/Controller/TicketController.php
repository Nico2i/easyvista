<?php

namespace App\Controller;

use App\EasyVista\Dto\Ticket;
use App\EasyVista\EasyVistaClientInterface;
use App\EasyVista\Exception\EasyVistaException;
use App\Security\PortalUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** « Mes demandes » : problèmes signalés et demandes, au même endroit. */
final class TicketController extends AbstractController
{
    /** @param array<string, mixed> $easyvistaOptions */
    public function __construct(
        private readonly EasyVistaClientInterface $easyVista,
        #[Autowire('%calipso.easyvista%')] private readonly array $easyvistaOptions,
    ) {
    }

    #[Route('/mes-demandes', name: 'app_my_requests', methods: ['GET'])]
    public function list(Request $request): Response
    {
        /** @var PortalUser $user */
        $user = $this->getUser();
        $filter = (string) $request->query->get('statut', 'en-cours');
        $error = null;
        $tickets = [];

        try {
            $tickets = $this->easyVista->listTickets($user->employee);
        } catch (EasyVistaException $e) {
            $error = $e->getMessage();
        }

        $counts = [
            'en-cours' => \count(array_filter($tickets, static fn (Ticket $t) => $t->isOpen())),
            'terminees' => \count(array_filter($tickets, static fn (Ticket $t) => !$t->isOpen())),
            'toutes' => \count($tickets),
        ];
        $shown = array_values(array_filter($tickets, static fn (Ticket $t) => match ($filter) {
            'terminees' => !$t->isOpen(),
            'toutes' => true,
            default => $t->isOpen(),
        }));

        $selectedNumber = (string) $request->query->get('rfc', '');
        $selected = null;
        foreach ($tickets as $t) {
            if ($t->number === $selectedNumber) {
                $selected = $t;
            }
        }
        if (null === $selected && '' !== $selectedNumber && null === $error) {
            try {
                $selected = $this->easyVista->getTicket($selectedNumber);
            } catch (EasyVistaException) {
            }
        }
        $selected ??= $shown[0] ?? null;

        return $this->render('tickets/list.html.twig', [
            'tickets' => $shown,
            'filter' => \array_key_exists($filter, $counts) ? $filter : 'en-cours',
            'counts' => $counts,
            'selected' => $selected,
            'canMessage' => $this->easyVista->isMock() || '' !== (string) ($this->easyvistaOptions['type_action_message'] ?? ''),
            'error' => $error,
        ]);
    }

    #[Route('/mes-demandes/{number}/message', name: 'app_ticket_message', methods: ['POST'])]
    public function message(string $number, Request $request): Response
    {
        /** @var PortalUser $user */
        $user = $this->getUser();
        $message = trim((string) $request->request->get('message', ''));

        if (!$this->isCsrfTokenValid('message'.$number, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire a expiré : merci de recommencer.');
        } elseif ('' === $message) {
            $this->addFlash('error', 'Votre message est vide.');
        } else {
            try {
                $this->easyVista->addMessage($number, $message, $user->employee);
                $this->addFlash('success', 'Votre message a été transmis aux intervenants.');
            } catch (EasyVistaException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_my_requests', ['rfc' => $number, 'statut' => $request->request->get('statut', 'en-cours')]);
    }
}
