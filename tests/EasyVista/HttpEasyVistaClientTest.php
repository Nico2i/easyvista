<?php

namespace App\Tests\EasyVista;

use App\EasyVista\Dto\Employee;
use App\EasyVista\Dto\NewTicket;
use App\EasyVista\Dto\Ticket;
use App\EasyVista\Exception\EasyVistaException;
use App\EasyVista\HttpEasyVistaClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Vérifie les appels envoyés à l'API EasyVista (conformes au swagger) sans serveur réel. */
final class HttpEasyVistaClientTest extends TestCase
{
    private const OPTIONS = [
        'langue' => 'fr',
        'envoyer_demandeur' => true,
        'lancer_workflow' => true,
        'recherche_mes_demandes' => 'requestor.e_mail:"{email}"',
        'nb_max_mes_demandes' => 50,
        'type_action_message' => '17',
        'statuts' => ['ok' => 'clotur|termin', 'wait' => 'attente|valid'],
    ];

    public function testCreateTicketPostsSwaggerPayloadAndReturnsNumber(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'], true), 'headers' => $options['headers']];

            return new JsonMockResponse(['HREF' => 'https://ev.example/api/v1/50004/requests/I261127_000042'], ['http_code' => 201]);
        }, 'https://ev.example/api/v1/50004/');

        $client = new HttpEasyVistaClient($http, self::OPTIONS, new NullLogger(), token: 'abc');
        $number = $client->createTicket(new NewTicket(
            NewTicket::INCIDENT, 'INC_IMP', 'Imprimante : bourrage', "Ligne 1\nLigne <2>", 'MARTIN Camille', 'camille@example.org',
            new Employee('1001', 'agent.demo', 'MARTIN Camille', 'camille@example.org'), 2,
        ));

        self::assertSame('I261127_000042', $number);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://ev.example/api/v1/50004/requests', $captured['url']);
        self::assertContains('Authorization: Bearer abc', $captured['headers']);
        $r = $captured['body']['requests'][0];
        self::assertSame('INC_IMP', $r['Catalog_Code']);
        self::assertSame(2, $r['Urgency_ID']);
        self::assertSame('camille@example.org', $r['Recipient_Mail']);
        self::assertSame('camille@example.org', $r['Requestor_Mail']);
        self::assertTrue($r['workflow_start']);
        self::assertStringContainsString('Ligne &lt;2&gt;', $r['Description']);
    }

    public function testListTicketsMapsStatuses(): void
    {
        $url = '';
        $http = new MockHttpClient(function (string $method, string $u) use (&$url) {
            $url = urldecode($u);

            return new JsonMockResponse(['records' => [
                ['RFC_NUMBER' => 'S261103_000101', 'SUBMIT_DATE_UT' => '2026-11-03T09:12:00.000Z', 'SD_CATALOG_PATH' => 'Demandes/Matériel/Demander un écran d\'ordinateur', 'STATUS' => ['STATUS_FR' => 'En attente de validation']],
                ['RFC_NUMBER' => 'I261001_000003', 'SUBMIT_DATE_UT' => '2026-10-01T08:00:00.000Z', 'STATUS' => ['STATUS_FR' => 'Clôturé']],
            ]]);
        }, 'https://ev.example/api/v1/50004/');

        $tickets = (new HttpEasyVistaClient($http, self::OPTIONS, new NullLogger()))->listTickets(new Employee('1', 'x', 'X', 'x@example.org'));

        self::assertStringContainsString('search=requestor.e_mail:"x@example.org"', $url);
        self::assertSame('Demander un écran d\'ordinateur', $tickets[0]->label);
        self::assertSame(Ticket::STATUS_WAIT, $tickets[0]->status);
        self::assertSame(Ticket::STATUS_DONE, $tickets[1]->status);
        self::assertTrue($tickets[1]->isIncident());
        self::assertSame('2026-11-03', $tickets[0]->submittedAt?->format('Y-m-d'));
    }

    public function testHttpErrorBecomesReadableException(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":"Invalid credentials"}', ['http_code' => 401]), 'https://ev.example/api/v1/50004/');

        $this->expectException(EasyVistaException::class);
        $this->expectExceptionMessage('refusé l\'authentification');
        (new HttpEasyVistaClient($http, self::OPTIONS, new NullLogger(), 'svc', 'pwd'))->findEmployeeByLogin('agent');
    }

    public function testCheckAccessReportsForbiddenEndpointsWithoutFailing(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url) {
            return str_contains($url, '/version')
                ? new MockResponse('{"error":"Unauthorized method for your profile"}', ['http_code' => 403])
                : new JsonMockResponse(['total_record_count' => '12', 'records' => []]);
        }, 'https://ev.example/api/v1/50004/');

        $access = array_column((new HttpEasyVistaClient($http, self::OPTIONS, new NullLogger()))->checkAccess(), null, 'endpoint');

        self::assertTrue($access['GET /employees']['ok']);
        self::assertSame('12 enregistrement(s)', $access['GET /employees']['detail']);
        self::assertFalse($access['GET /version']['ok']);
        self::assertStringContainsString('profil du compte API n\'autorise pas GET /version', $access['GET /version']['detail']);
        self::assertStringContainsString('Unauthorized method for your profile', $access['GET /version']['detail']);
    }

    public function testFindCatalogCodeMatchesTitle(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['records' => [
            ['CODE' => 'S_OTHER', 'TITLE_FR' => 'Demander un écran géant'],
            ['CODE' => 'S_ECRAN', 'TITLE_FR' => "Demander un écran d'ordinateur"],
        ]]), 'https://ev.example/api/v1/50004/');

        self::assertSame('S_ECRAN', (new HttpEasyVistaClient($http, self::OPTIONS, new NullLogger()))->findCatalogCode("Demander un écran d'ordinateur"));
    }
}
