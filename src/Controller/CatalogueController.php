<?php

namespace App\Controller;

use App\Catalog\Catalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogueController extends AbstractController
{
    #[Route('/catalogue/{famille}', name: 'app_catalogue', methods: ['GET'])]
    public function family(string $famille, Catalog $catalog): Response
    {
        $family = $catalog->family($famille) ?? throw $this->createNotFoundException('Rubrique inconnue.');

        return $this->render('catalogue/family.html.twig', [
            'key' => $famille,
            'family' => $family,
            'services' => $catalog->servicesOfFamily($famille),
            'families' => $catalog->families(),
        ]);
    }
}
