<?php

namespace App\Controller;

use App\Catalog\Catalog;
use App\EasyVista\CatalogCodeResolver;
use App\EasyVista\EasyVistaClientInterface;
use App\EasyVista\Exception\EasyVistaException;
use App\Orientation\OrientationResult;
use App\Orientation\Orienter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * @param list<string> $examples
     * @param list<string> $situations
     * @param list<string> $shortcuts
     * @param list<string> $testPhrases
     */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Orienter $orienter,
        #[Autowire('%calipso.exemples%')] private readonly array $examples,
        #[Autowire('%calipso.situations%')] private readonly array $situations,
        #[Autowire('%calipso.raccourcis%')] private readonly array $shortcuts,
        #[Autowire('%calipso.phrases_test%')] private readonly array $testPhrases,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        return $this->render('home/index.html.twig', [
            'q' => $q,
            'examples' => $this->examples,
            'situations' => $this->situations,
            'shortcuts' => array_values(array_filter($this->shortcuts, fn (string $t) => null !== $this->catalog->service($t))),
            'accessCount' => \count($this->catalog->accessResources()),
        ] + $this->resultsContext($q, $request->query->get('mode')));
    }

    /** Résultats de l'orientation, rechargés en direct pendant la saisie. */
    #[Route('/orientation', name: 'app_orientation', methods: ['GET'])]
    public function orientation(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('app_home', array_filter(['q' => $q, 'mode' => $request->query->get('mode')]));
        }

        return $this->render('home/_results.html.twig', [
            'q' => $q,
            'situations' => $this->situations,
            'accessCount' => \count($this->catalog->accessResources()),
        ] + $this->resultsContext($q, $request->query->get('mode')));
    }

    #[Route('/comment-ca-marche', name: 'app_logic', methods: ['GET'])]
    public function logic(CatalogCodeResolver $codes): Response
    {
        $rows = [];
        foreach ($this->testPhrases as $phrase) {
            $r = $this->orienter->orient($phrase);
            $rows[] = [
                'phrase' => $phrase,
                'lecture' => match ($r->mode) {
                    OrientationResult::PROBLEM => 'Problème',
                    OrientationResult::NEED => 'Besoin',
                    default => 'Question posée à l\'agent',
                },
                'premier' => $r->firstSuggestion($codes->incidentPrefix(), $this->catalog->families(), $this->catalog->accessTypes()),
            ];
        }

        return $this->render('home/logic.html.twig', [
            'rows' => $rows,
            'besoinsCount' => \count($this->getParameter('calipso.besoins')),
            'incidentsCount' => \count($this->catalog->incidents()),
            'servicesCount' => \count($this->catalog->services()),
            'accessCount' => \count($this->catalog->accessResources()),
            'accessEntries' => $this->catalog->accessEntriesCount(),
        ]);
    }

    #[Route('/a-traiter', name: 'app_todo', methods: ['GET'])]
    public function todo(): Response
    {
        return $this->render('home/todo.html.twig');
    }

    /** Autocomplétion du champ « collègue ». */
    #[Route('/collegues', name: 'app_colleagues', methods: ['GET'])]
    public function colleagues(Request $request, EasyVistaClientInterface $easyVista): JsonResponse
    {
        try {
            $people = $easyVista->searchEmployees((string) $request->query->get('q', ''), 8);
        } catch (EasyVistaException) {
            $people = [];
        }

        return $this->json(array_map(static fn ($e) => [
            'nom' => $e->name,
            'email' => $e->email,
            'service' => $e->departmentPath,
            'valeur' => $e->name.('' !== $e->email ? ' <'.$e->email.'>' : ''),
        ], $people));
    }

    /** @return array{result: ?OrientationResult, mode: ?string} */
    private function resultsContext(string $q, mixed $mode): array
    {
        if ('' === $q) {
            return ['result' => null, 'mode' => null];
        }
        $result = $this->orienter->orient($q);
        $mode = \in_array($mode, [OrientationResult::PROBLEM, OrientationResult::NEED], true) ? $mode : $result->mode;

        return ['result' => $result, 'mode' => $mode];
    }
}
