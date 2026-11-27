<?php

namespace App\Controller;

use App\Catalog\Catalog;
use App\Catalog\Model\AccessResource;
use App\Catalog\Model\ServiceItem;
use App\EasyVista\CatalogCodeResolver;
use App\EasyVista\Exception\EasyVistaException;
use App\Security\PortalUser;
use App\Service\Beneficiary;
use App\Service\Counters;
use App\Service\SubmittedTicket;
use App\Service\TicketSubmitter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Les trois parcours d'envoi : signaler un problème, faire une demande, gérer un accès. */
final class SubmitController extends AbstractController
{
    private const SESSION_SENT = 'calipso_sent';

    /** @param array<string, list<string>> $tips */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly TicketSubmitter $submitter,
        private readonly Counters $counters,
        #[Autowire('%calipso.conseils%')] private readonly array $tips,
    ) {
    }

    #[Route('/signaler', name: 'app_incident', methods: ['GET', 'POST'])]
    public function incident(Request $request, CatalogCodeResolver $codes): Response
    {
        $key = (string) ($request->request->get('sujet') ?? $request->query->get('sujet', ''));
        $subject = '' !== $key ? $this->catalog->incident($key) : null;
        $error = null;

        if ($request->isMethod('POST')) {
            try {
                $this->checkCsrf($request, 'incident');
                $user = $this->portalUser();
                $sent = $this->submitter->submitIncident(
                    $user,
                    $subject,
                    (string) $request->request->get('description', ''),
                    (string) $request->request->get('gene', 'attente'),
                    Beneficiary::fromRequest($request, $user),
                    (string) $request->request->get('telephone', ''),
                    $this->uploadedFile($request),
                );

                return $this->sent($request, $sent);
            } catch (\InvalidArgumentException|EasyVistaException $e) {
                $error = $e->getMessage();
            }
        }

        $category = 'def';
        foreach ($subject?->categories ?? [] as $c) {
            if (isset($this->tips[$c])) {
                $category = $c;
                break;
            }
        }

        return $this->render('submit/incident.html.twig', [
            'subject' => $subject,
            'incidents' => $subject ? [] : $this->catalog->incidents(),
            'categories' => $this->catalog->incidentCategories(),
            'genes' => TicketSubmitter::GENES,
            'tips' => $this->tips[$category] ?? $this->tips['def'] ?? [],
            'incidentPrefix' => $codes->incidentPrefix(),
            'maxFileMb' => $this->submitter->maxFileSize() / 1024 / 1024,
            'error' => $error,
            'form' => $request->request->all(),
        ], new Response(status: null === $error ? 200 : 422));
    }

    #[Route('/demande', name: 'app_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        $title = (string) ($request->request->get('service') ?? $request->query->get('service', ''));
        $service = $this->catalog->service($title)
            ?? ('Demande générique' === $title ? new ServiceItem('Demande générique', 'proj') : null);
        if (null === $service) {
            $this->addFlash('error', 'Ce service n\'existe pas ou plus dans le catalogue.');

            return $this->redirectToRoute('app_home');
        }
        $error = null;

        if ($request->isMethod('POST')) {
            try {
                $this->checkCsrf($request, 'request');
                $user = $this->portalUser();
                $sent = $this->submitter->submitService(
                    $user,
                    $service,
                    (string) $request->request->get('precisions', ''),
                    (int) $request->request->get('quantite', 1),
                    Beneficiary::fromRequest($request, $user),
                    $this->uploadedFile($request),
                );

                return $this->sent($request, $sent);
            } catch (\InvalidArgumentException|EasyVistaException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('submit/request.html.twig', [
            'service' => $service,
            'family' => $this->catalog->family($service->family),
            'maxFileMb' => $this->submitter->maxFileSize() / 1024 / 1024,
            'error' => $error,
            'form' => $request->request->all(),
        ], new Response(status: null === $error ? 200 : 422));
    }

    #[Route('/acces', name: 'app_access', methods: ['GET', 'POST'])]
    public function access(Request $request): Response
    {
        $bag = $request->isMethod('POST') ? $request->request : $request->query;
        $action = (int) $bag->get('action', AccessResource::GRANT);
        if (!isset(AccessResource::ACTIONS[$action])) {
            $action = AccessResource::GRANT;
        }
        $type = (string) $request->query->get('type', 'all');
        if ('all' !== $type && !isset($this->catalog->accessTypes()[$type])) {
            $type = 'all';
        }
        $resource = $this->catalog->accessResource((string) $bag->get('ressource', ''));
        if (null !== $resource && !$resource->allows($action)) {
            $resource = null;
        }
        $error = null;

        if ($request->isMethod('POST') && null !== $resource) {
            try {
                $this->checkCsrf($request, 'access');
                $user = $this->portalUser();
                $sent = $this->submitter->submitAccess($user, $resource, $action, (string) $request->request->get('justification', ''), Beneficiary::fromRequest($request, $user));

                return $this->sent($request, $sent);
            } catch (\InvalidArgumentException|EasyVistaException $e) {
                $error = $e->getMessage();
            }
        }

        $resources = $resource ? [] : array_values(array_filter(
            $this->catalog->accessResources(),
            static fn (AccessResource $r) => $r->allows($action),
        ));

        return $this->render('submit/access.html.twig', [
            'action' => $action,
            'actions' => AccessResource::ACTIONS,
            'type' => $type,
            'types' => $this->catalog->accessTypes(),
            'counts' => $this->catalog->accessCounts(),
            'resource' => $resource,
            'resources' => $resources,
            'accessEntries' => $this->catalog->accessEntriesCount(),
            'accessCount' => \count($this->catalog->accessResources()),
            'error' => $error,
            'form' => $request->request->all(),
        ], new Response(status: null === $error ? 200 : 422));
    }

    #[Route('/envoye/{number}', name: 'app_sent', methods: ['GET'])]
    public function confirmation(string $number, Request $request): Response
    {
        $data = $request->getSession()->get(self::SESSION_SENT);
        $sent = \is_array($data) ? SubmittedTicket::fromArray($data) : null;
        if (null === $sent || $sent->number !== $number) {
            return $this->redirectToRoute('app_my_requests', ['rfc' => $number]);
        }

        return $this->render('submit/sent.html.twig', ['sent' => $sent]);
    }

    private function sent(Request $request, SubmittedTicket $sent): Response
    {
        $request->getSession()->set(self::SESSION_SENT, $sent->toArray());
        $this->counters->reset($this->portalUser());

        return $this->redirectToRoute('app_sent', ['number' => $sent->number]);
    }

    private function portalUser(): PortalUser
    {
        $user = $this->getUser();
        if (!$user instanceof PortalUser) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function checkCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw new \InvalidArgumentException('Le formulaire a expiré : merci de le renvoyer.');
        }
    }

    private function uploadedFile(Request $request): ?UploadedFile
    {
        $file = $request->files->get('piece_jointe');

        return $file instanceof UploadedFile && \UPLOAD_ERR_NO_FILE !== $file->getError() ? $file : null;
    }
}
