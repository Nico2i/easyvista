<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Parcours complets en mode démonstration (EASYVISTA_MODE=mock, agent CALIPSO_DEV_LOGIN). */
final class PortalTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/mock');
    }

    public function testHomeGreetsAgentAndShowsSituations(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bonjour Camille');
        self::assertSelectorTextContains('.situations', 'Quelque chose ne fonctionne plus');
        self::assertSelectorExists('.sit[href="/catalogue/mat"]');
    }

    public function testHomeWithQueryRendersResultsWithoutJavascript(): void
    {
        $this->client->request('GET', '/', ['q' => 'Mon imprimante n\'imprime plus']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#results .group h3', 'Signaler que ça ne fonctionne plus');
        self::assertSelectorTextContains('#results .row strong', 'Imprimante ne fonctionne pas correctement');
    }

    public function testOrientationFragment(): void
    {
        $this->client->xmlHttpRequest('GET', '/orientation', ['q' => 'Un stagiaire arrive lundi']);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('.group h3', 'Faire une demande');
        self::assertSelectorTextContains('.group .row strong', 'Déclarer l\'arrivée d\'un agent');
    }

    public function testClarificationCanBeForced(): void
    {
        $this->client->xmlHttpRequest('GET', '/orientation', ['q' => 'GECO', 'mode' => 'd']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.seg a[data-mode="d"][aria-current="true"]');
    }

    public function testReportIncidentEndToEnd(): void
    {
        $crawler = $this->client->request('GET', '/signaler', ['sujet' => 'Equipement de proximité/Impression/Imprimante']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.picked b', 'Imprimante');

        $form = $crawler->selectButton('Envoyer à la hotline')->form([
            'description' => 'L\'imprimante du 2e étage affiche « bourrage » en permanence.',
            'gene' => 'gene',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Votre signalement est enregistré');
        self::assertSelectorTextContains('.ticket', 'I'.date('ymd'));

        $this->client->request('GET', '/mes-demandes');
        self::assertSelectorTextContains('#ticket-list', 'Imprimante : L\'imprimante du 2e étage');
    }

    public function testIncidentWithoutDescriptionIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/signaler');
        $form = $crawler->selectButton('Envoyer à la hotline')->form(['description' => '   ']);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert.error', 'Décrivez ce qui se passe');
    }

    public function testServiceRequestForColleague(): void
    {
        $crawler = $this->client->request('GET', '/demande', ['service' => 'Demander un écran d\'ordinateur']);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Envoyer la demande')->form([
            'pour' => 'collegue',
            'collegue' => 'DURAND Claire <claire.durand@example.org>',
            'precisions' => 'Second écran pour le poste 214',
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('h1', 'Votre demande est envoyée');
        $stored = json_decode((string) file_get_contents(self::getContainer()->getParameter('kernel.project_dir').'/var/mock/tickets.json'), true);
        self::assertSame('DURAND Claire', $stored[0]['recipient']);
        self::assertStringContainsString('Demandé par MARTIN Camille', $stored[0]['description']);
    }

    public function testUnknownServiceRedirectsHome(): void
    {
        $this->client->request('GET', '/demande', ['service' => 'Service imaginaire']);

        self::assertResponseRedirects('/');
    }

    public function testAccessJourney(): void
    {
        $crawler = $this->client->request('GET', '/acces', ['type' => 'q']);
        self::assertResponseIsSuccessful();
        $link = $crawler->filter('#access-list a.row')->first();
        $name = trim($link->filter('strong')->text());

        $crawler = $this->client->click($link->link());
        self::assertSelectorTextContains('.picked b', $name);

        $this->client->submit($crawler->selectButton('Envoyer la demande')->form(['justification' => 'Nouvelle mission']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.done p', 'Demander à accéder à '.$name);
    }

    public function testCatalogueFamilyAndLogicPages(): void
    {
        $this->client->request('GET', '/catalogue/resa');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Réserver une salle');

        $this->client->request('GET', '/catalogue/inconnue');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/comment-ca-marche');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'INCIDENT · Incidents/IT/Equipement de proximité/Impression/Imprimante');
    }

    public function testMyRequestsListLinksToDrawer(): void
    {
        $this->client->request('GET', '/mes-demandes', ['statut' => 'toutes']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#ticket-list tr.tkrow[data-drawer="/mes-demandes/S261103_000101?statut=toutes"] a.tk-num[href="/mes-demandes/S261103_000101?statut=toutes"]');
        self::assertSelectorExists('aside.drawer[aria-hidden="true"]:not(.open)');
    }

    public function testFilterByType(): void
    {
        $crawler = $this->client->request('GET', '/mes-demandes', ['statut' => 'toutes', 'type' => 'problemes']);

        self::assertResponseIsSuccessful();
        $numbers = $crawler->filter('#ticket-list .tkrow .tk-num')->each(static fn ($n) => $n->text());
        self::assertNotEmpty($numbers);
        foreach ($numbers as $n) {
            self::assertStringStartsWith('I', $n);
        }
        // Compteurs : 2 problèmes et 6 demandes parmi toutes ; parmi les problèmes, 1 terminé
        self::assertSelectorTextContains('.type-seg a[aria-current="true"]', 'Problèmes 2');
        self::assertSelectorTextContains('.type-seg a[href$="type=demandes"]', 'Demandes 6');
        self::assertSelectorTextContains('.filters a[href*="statut=terminees"]', 'Terminées 1');
        // Les filtres sont conservés vers le panneau et à sa fermeture
        self::assertSelectorExists('.tkrow[data-drawer="/mes-demandes/I261030_000018?statut=toutes&type=problemes"]');

        $this->client->request('GET', '/mes-demandes/I261030_000018', ['statut' => 'toutes', 'type' => 'problemes']);
        self::assertSelectorExists('.drawer[data-list-url="/mes-demandes?statut=toutes&type=problemes"]');
        self::assertSelectorExists('.composer input[name="type"][value="problemes"]');
    }

    public function testDemandesOnly(): void
    {
        $crawler = $this->client->request('GET', '/mes-demandes', ['type' => 'demandes']);

        foreach ($crawler->filter('#ticket-list .tkrow .tk-num')->each(static fn ($n) => $n->text()) as $n) {
            self::assertStringStartsWith('S', $n);
        }
        // « Tout » retire le filtre de type mais garde l'avancement
        self::assertSelectorExists('.type-seg a[href="/mes-demandes?statut=en-cours"]');
    }

    public function testTableHasSearchSortAndColumnFilters(): void
    {
        $crawler = $this->client->request('GET', '/mes-demandes', ['statut' => 'toutes']);

        self::assertSelectorExists('input[data-table-search]');
        self::assertSame(['number', 'type', 'label', 'date', 'status'], $crawler->filter('#ticket-list th button[data-sort]')->each(static fn ($b) => $b->attr('data-sort')));
        self::assertSelectorExists('#ticket-list th.c-date[aria-sort="descending"]');
        self::assertSame(['number', 'type', 'label', 'date', 'status'], $crawler->filter('#ticket-list [data-filter-col]')->each(static fn ($f) => $f->attr('data-filter-col')));
        // Le filtre « Statut » propose les statuts présents dans la liste
        $statuses = $crawler->filter('select[data-filter-col="status"] option')->each(static fn ($o) => $o->attr('value'));
        self::assertContains('Clôturé', $statuses);
        self::assertContains('En attente de validation', $statuses);
        // Numéro en gras dans la première colonne, données pour le tri et les filtres sur la ligne
        $row = $crawler->filter('#ticket-list tr.tkrow[data-number="I261030_000018"]');
        self::assertSame('I261030_000018', trim($row->filter('td')->first()->filter('a.tk-num')->text()));
        self::assertSame('Problème', $row->attr('data-type'));
        self::assertSame('En cours de traitement', $row->attr('data-status'));
        self::assertStringStartsWith('2026-10-30T08:55', (string) $row->attr('data-date'));
    }

    public function testOldUrlRedirectsToDrawer(): void
    {
        $this->client->request('GET', '/mes-demandes', ['statut' => 'toutes', 'rfc' => 'S261103_000101']);

        self::assertResponseRedirects('/mes-demandes/S261103_000101?statut=toutes');
    }

    public function testDrawerPageShowsDetailTimelineAndValidation(): void
    {
        $this->client->request('GET', '/mes-demandes/S261103_000101', ['statut' => 'toutes']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('aside.drawer.open');
        self::assertSelectorTextContains('#dr-title', 'Demander le référencement d\'un nouvel espace GECO');
        self::assertSelectorTextContains('.track li.cur', 'Validation');
        self::assertSelectorTextContains('.callout.wait', 'l\'accord de LE BRAS Morgane');
        self::assertSelectorTextContains('.desc', 'Archives numériques 2027');
        self::assertSelectorTextContains('.tl li:first-child', 'Demande envoyée');
        self::assertSelectorTextContains('.tl li.now', 'Validation par le responsable hiérarchique');
        self::assertSelectorTextContains('.tl li.future', 'Traitement après validation');
        self::assertSelectorExists('#ticket-list tr.tkrow[aria-current="true"]');
    }

    public function testDrawerFragmentForJavascript(): void
    {
        $this->client->xmlHttpRequest('GET', '/mes-demandes/I261030_000018');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<html', $html);
        self::assertStringContainsString('Message de l&#039;intervenant', $html);
        self::assertStringContainsString('photo-ecran-multifonction.jpg', $html);
    }

    public function testClosedTicketShowsResolutionAndNoComposer(): void
    {
        $this->client->request('GET', '/mes-demandes/I260915_000007');

        self::assertSelectorTextContains('.callout.ok', 'Profil reconstruit');
        self::assertSelectorTextContains('.track li:last-child', 'Terminée');
        self::assertSelectorNotExists('.composer textarea');
        self::assertSelectorTextContains('.composer a.btn', 'Signaler à nouveau');
    }

    public function testUnknownTicketIs404(): void
    {
        $this->client->request('GET', '/mes-demandes/S999999_999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testMessageFromDrawerAppearsInTimeline(): void
    {
        $crawler = $this->client->request('GET', '/mes-demandes/S261103_000101', ['statut' => 'toutes']);
        $this->client->submit($crawler->filter('form.composer')->form(['message' => 'Pouvez-vous accélérer ?']));

        self::assertResponseRedirects('/mes-demandes/S261103_000101?statut=toutes');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.drawer .alert.success', 'transmis aux intervenants');
        self::assertSelectorTextContains('.tl li.mine', 'Votre message');
        self::assertSelectorTextContains('.tl li.mine .msg', 'Pouvez-vous accélérer ?');
    }

    public function testAttachAndDownloadDocument(): void
    {
        // Une demande créée pendant le test, pour que la pièce jointe soit conservée
        $crawler = $this->client->request('GET', '/demande', ['service' => 'Demander un écran d\'ordinateur']);
        $this->client->submit($crawler->selectButton('Envoyer la demande')->form());
        $number = basename((string) $this->client->getResponse()->headers->get('Location'));

        $file = tempnam(sys_get_temp_dir(), 'pj');
        file_put_contents($file, 'contenu de test');
        $crawler = $this->client->request('GET', '/mes-demandes/'.$number);
        $form = $crawler->filter('form.attach')->form();
        $this->client->request('POST', $form->getUri(), ['_token' => $form['_token']->getValue(), 'statut' => 'en-cours'], ['piece_jointe' => new UploadedFile($file, 'capture.txt', 'text/plain', null, true)]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.drawer .alert.success', 'capture.txt');
        $link = $this->client->getCrawler()->filter('.files a.file')->link();
        $this->client->click($link);
        self::assertResponseIsSuccessful();
        self::assertSame('contenu de test', $this->client->getInternalResponse()->getContent());
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testColleagueSearch(): void
    {
        $this->client->request('GET', '/collegues', ['q' => 'durand']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('DURAND Claire', (string) $this->client->getResponse()->getContent());
    }
}
