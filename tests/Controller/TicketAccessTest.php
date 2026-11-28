<?php

namespace App\Tests\Controller;

use App\EasyVista\Dto\Employee;
use App\EasyVista\Dto\Ticket;
use App\EasyVista\Dto\TicketDetail;
use App\EasyVista\EasyVistaClientInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * En mode API, un agent ne doit voir que les demandes dont il est demandeur ou bénéficiaire,
 * même en tapant le numéro d'une autre demande dans l'adresse.
 */
final class TicketAccessTest extends WebTestCase
{
    private const ME = ['1001', 'agent.demo', 'MARTIN Camille', 'camille.martin@example.org'];

    public function testOtherAgentsTicketIsHidden(): void
    {
        $other = new Employee('2002', '', 'DURAND Claire', 'claire.durand@example.org');
        $client = $this->clientWith($this->detail($other, $other));

        $client->request('GET', '/mes-demandes/S261120_000001');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/mes-demandes/S261120_000001/documents/1');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnTicketAsRecipientIsShown(): void
    {
        $other = new Employee('2002', '', 'DURAND Claire', 'claire.durand@example.org');
        // Bénéficiaire reconnu par son adresse, sans identifiant
        $client = $this->clientWith($this->detail(new Employee('', '', 'MARTIN Camille', 'Camille.Martin@example.org'), $other));

        $client->request('GET', '/mes-demandes/S261120_000001');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#dr-title', 'Demander un écran');
        // Le demandeur n'est pas l'agent : l'envoi est attribué à la collègue
        self::assertSelectorTextContains('.tl li:first-child .who', 'DURAND Claire');
    }

    private function detail(Employee $recipient, Employee $requestor): TicketDetail
    {
        $ticket = new Ticket('S261120_000001', 'Demander un écran d\'ordinateur', new \DateTimeImmutable('2026-11-20 10:00'), 'En cours', Ticket::STATUS_RUNNING);

        return new TicketDetail($ticket, '555', 'Pour le poste 214', $recipient, $requestor);
    }

    private function clientWith(TicketDetail $detail): KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();

        $easyVista = $this->createStub(EasyVistaClientInterface::class);
        $easyVista->method('isMock')->willReturn(false);
        $easyVista->method('findEmployeeByLogin')->willReturn(new Employee(...self::ME));
        $easyVista->method('listTickets')->willReturn([]);
        $easyVista->method('getTicketDetail')->willReturn($detail);
        $easyVista->method('listTicketEvents')->willReturn([]);
        $easyVista->method('listDocuments')->willReturn([]);
        $easyVista->method('downloadDocument')->willReturn(['filename' => 'x.txt', 'content' => 'secret', 'contentType' => 'text/plain']);
        self::getContainer()->set(EasyVistaClientInterface::class, $easyVista);

        return $client;
    }
}
