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
            return str_contains($url, '/catalog-requests')
                ? new MockResponse('{"error":"Unauthorized method for your profile"}', ['http_code' => 403])
                : new JsonMockResponse(['total_record_count' => '12', 'records' => []]);
        }, 'https://ev.example/api/v1/50004/');

        $access = array_column((new HttpEasyVistaClient($http, self::OPTIONS, new NullLogger()))->checkAccess(), null, 'endpoint');

        self::assertSame(['GET /employees', 'GET /requests', 'GET /catalog-requests', 'GET /urgencies'], array_keys($access));
        self::assertTrue($access['GET /employees']['ok']);
        self::assertSame('12 enregistrement(s)', $access['GET /employees']['detail']);
        self::assertTrue($access['GET /urgencies']['ok']);
        self::assertFalse($access['GET /catalog-requests']['ok']);
        self::assertStringContainsString('profil du compte API n\'autorise pas GET /catalog-requests', $access['GET /catalog-requests']['detail']);
        self::assertStringContainsString('Unauthorized method for your profile', $access['GET /catalog-requests']['detail']);
    }

    public function testEmployeeIsSearchedOnEachConfiguredField(): void
    {
        $searches = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$searches) {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $q);
            $searches[] = $q['search'];

            return new JsonMockResponse(['records' => str_starts_with($q['search'], 'identification:')
                ? [['EMPLOYEE_ID' => '42', 'LAST_NAME' => 'DUPONT Jean', 'IDENTIFICATION' => 'jdupont']]
                : []]);
        }, 'https://ev.example/api/v1/50004/');

        $client = new HttpEasyVistaClient($http, ['champs_identifiant' => ['login', 'identification']] + self::OPTIONS, new NullLogger());
        $employee = $client->findEmployeeByLogin('jdupont');

        self::assertSame('42', $employee?->id);
        self::assertSame(['login:"jdupont"', 'identification:"jdupont"'], $searches);
        self::assertSame([['search' => 'login:"jdupont"', 'count' => 0], ['search' => 'identification:"jdupont"', 'count' => 1]], $client->lastEmployeeSearches());
    }

    public function testTicketDetailAndTimelineFollowLinks(): void
    {
        $base = 'https://ev.example/api/v1/50004/';
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$calls, $base) {
            $calls[] = urldecode($url);
            $path = substr(parse_url($url, \PHP_URL_PATH), \strlen('/api/v1/50004/'));

            return match (true) {
                'requests/I261127_000101' === $path => new JsonMockResponse([
                    'RFC_NUMBER' => 'I261127_000101', 'REQUEST_ID' => '987', 'SUBMIT_DATE_UT' => '2026-11-27T07:55:00.000Z',
                    'SD_CATALOG_PATH' => 'Incidents/IT/Equipement de proximité/Impression/Imprimante',
                    'STATUS' => ['STATUS_FR' => 'En cours'],
                    'DESCRIPTION' => ['HREF' => $base.'requests/I261127_000101/description'],
                    'RECIPIENT' => ['HREF' => $base.'employees/1001', 'LAST_NAME' => 'MARTIN Camille', 'E_MAIL' => 'camille@example.org'],
                    'REQUESTOR' => ['HREF' => $base.'employees/1001', 'LAST_NAME' => 'MARTIN Camille', 'E_MAIL' => 'camille@example.org'],
                    'LOCATION' => ['LOCATION_PATH' => 'Saint-Brieuc/Siège'],
                ]),
                'requests/I261127_000101/description' === $path => new JsonMockResponse(['HREF' => 'x', 'DESCRIPTION' => '<p>Bourrage&nbsp;permanent</p><p>2e étage</p>']),
                'actions' === $path => new JsonMockResponse(['records' => [
                    ['ACTION_ID' => '2', 'ACTION_LABEL_FR' => 'Intervention', 'ACTION_TYPE' => ['NAME_FR' => 'Intervention'], 'START_DATE_UT' => '2026-11-27T10:00:00.000Z', 'DONE_BY' => ['LAST_NAME' => 'TANGUY Loïc', 'EMPLOYEE_ID' => '1003'], 'COMMENT' => ['HREF' => $base.'actions/2/comment']],
                    ['ACTION_ID' => '1', 'ACTION_LABEL_FR' => 'Prise en compte', 'ACTION_TYPE' => ['NAME_FR' => 'Prise en compte'], 'START_DATE_UT' => '2026-11-27T08:00:00.000Z', 'END_DATE_UT' => '2026-11-27T08:00:00.000Z'],
                ]]),
                'actions/2/comment' === $path => new JsonMockResponse(['HREF' => 'x', 'PARENT_HREF' => 'y', 'COMMENT' => 'Je passe à 11h<br>Merci']),
                default => new MockResponse('', ['http_code' => 404]),
            };
        }, $base);

        $client = new HttpEasyVistaClient($http, self::OPTIONS + ['recherche_actions' => 'request_id:{request_id}', 'champs_actions' => ''], new NullLogger());
        $detail = $client->getTicketDetail('I261127_000101');

        self::assertNotNull($detail);
        self::assertSame("Bourrage permanent\n2e étage", $detail->description);
        self::assertSame('1001', $detail->requestor?->id);
        self::assertTrue($detail->concerns(new Employee('1001', 'x', 'X')));
        self::assertFalse($detail->concerns(new Employee('9', 'y', 'Y', 'autre@example.org')));
        self::assertSame('Saint-Brieuc/Siège', $detail->locationPath);

        $events = $client->listTicketEvents($detail);
        $actionsCall = (string) current(array_filter($calls, static fn (string $c) => str_contains($c, '/actions?')));
        self::assertStringContainsString('search=request_id:987', $actionsCall);
        self::assertStringContainsString('sort=start_date_ut', $actionsCall);
        self::assertSame(['1', '2'], array_map(static fn ($e) => $e->id, $events), 'triés par date');
        self::assertSame("Je passe à 11h\nMerci", $events[1]->comment);
        self::assertTrue($events[1]->isOpen());
        self::assertSame('TANGUY Loïc', $events[1]->authorName);
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
