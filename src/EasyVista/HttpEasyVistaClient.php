<?php

namespace App\EasyVista;

use App\EasyVista\Dto\Employee;
use App\EasyVista\Dto\NewTicket;
use App\EasyVista\Dto\Ticket;
use App\EasyVista\Exception\EasyVistaException;
use App\Text\TextNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Client de l'API REST EasyVista Service Manager v1 (https://{serveur}/api/v1/{compte}/).
 *
 * Référence : docs/easyvista-swagger.json. Les noms de champs et la syntaxe de « search »
 * suivent les exemples du swagger ; les points dépendant du paramétrage de l'instance
 * sont réglables dans config/calipso/easyvista.yaml.
 */
class HttpEasyVistaClient implements EasyVistaClientInterface
{
    private const EMPLOYEE_FIELDS = 'employee_id,last_name,e_mail,login,phone_number,department_path,location_path';

    /** @param array<string, mixed> $easyvistaOptions */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly array $easyvistaOptions,
        private readonly LoggerInterface $logger,
        private readonly string $login = '',
        private readonly string $password = '',
        private readonly string $token = '',
    ) {
    }

    public function isMock(): bool
    {
        return false;
    }

    public function checkAccess(): array
    {
        $lang = $this->lang();
        $probes = [
            ['GET /employees', 'Identification des agents, recherche de collègues', 'employees', ['max_rows' => 1, 'fields' => 'employee_id']],
            ['GET /requests', 'Mes demandes', 'requests', ['max_rows' => 1, 'fields' => 'rfc_number']],
            ['GET /catalog-requests', 'Codes catalogue', 'catalog-requests', ['max_rows' => 1, 'fields' => 'code,title_'.$lang]],
            // Le swagger indique /urgency, mais l'instance expose /urgencies ; /version n'existe pas.
            ['GET /urgencies', 'Liste des urgences (réglage « urgences »)', 'urgencies', []],
        ];

        $results = [];
        foreach ($probes as [$endpoint, $usage, $path, $query]) {
            try {
                $data = $this->getJson($path, $query);
                $detail = isset($data['total_record_count']) ? $data['total_record_count'].' enregistrement(s)' : 'accessible';
                $results[] = ['endpoint' => $endpoint, 'usage' => $usage, 'ok' => true, 'detail' => $detail];
            } catch (EasyVistaException $e) {
                $results[] = ['endpoint' => $endpoint, 'usage' => $usage, 'ok' => false, 'detail' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function findEmployeeByLogin(string $login): ?Employee
    {
        $data = $this->getJson('employees', [
            'max_rows' => 2,
            'fields' => self::EMPLOYEE_FIELDS,
            'search' => sprintf('login:"%s"', self::quote($login)),
        ]);
        $record = $data['records'][0] ?? null;

        return \is_array($record) ? $this->toEmployee($record) : null;
    }

    public function searchEmployees(string $query, int $max = 10): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $data = $this->getJson('employees', [
            'max_rows' => $max,
            'fields' => self::EMPLOYEE_FIELDS,
            'sort' => 'last_name+asc',
            'search' => sprintf('last_name~"*%s*"', self::quote($query)),
        ]);

        return array_values(array_map(fn (array $r) => $this->toEmployee($r), array_filter($data['records'] ?? [], 'is_array')));
    }

    public function createTicket(NewTicket $ticket): string
    {
        $payload = array_filter([
            'Catalog_Code' => $ticket->catalogCode,
            'TITLE' => mb_substr($ticket->title, 0, 250),
            'Description' => nl2br(htmlspecialchars($ticket->description, \ENT_QUOTES)),
            'Recipient_Name' => $ticket->recipientName,
            'Recipient_Mail' => $ticket->recipientEmail,
            'Urgency_ID' => $ticket->urgencyId,
        ], static fn ($v) => null !== $v && '' !== $v);

        if (($this->easyvistaOptions['envoyer_demandeur'] ?? false) && null !== $ticket->requestor) {
            $payload['Requestor_Name'] = $ticket->requestor->name;
            if ('' !== $ticket->requestor->email) {
                $payload['Requestor_Mail'] = $ticket->requestor->email;
            }
        }
        $payload['workflow_start'] = (bool) ($this->easyvistaOptions['lancer_workflow'] ?? true);

        $response = $this->request('POST', 'requests', ['json' => ['requests' => [$payload]]]);
        $data = $this->decode($response);
        $href = (string) ($data['HREF'] ?? $data['href'] ?? ($response->getHeaders(false)['location'][0] ?? ''));
        $number = basename(parse_url($href, \PHP_URL_PATH) ?: $href);

        if ('' === $number) {
            throw new EasyVistaException('EasyVista n\'a pas renvoyé le numéro de la demande créée.');
        }
        $this->logger->info('EasyVista : {number} créé (catalogue {code})', ['number' => $number, 'code' => $ticket->catalogCode]);

        return $number;
    }

    public function attachDocument(string $number, string $filename, string $content): void
    {
        // À VÉRIFIER : le swagger ne détaille pas le corps attendu ; format JSON base64 de l'exemple de réponse.
        $this->request('POST', sprintf('requests/%s/documents', rawurlencode($number)), ['json' => [
            'documents' => [['filename' => $filename, 'filedata' => base64_encode($content)]],
        ]]);
    }

    public function listTickets(Employee $employee): array
    {
        $search = strtr((string) ($this->easyvistaOptions['recherche_mes_demandes'] ?? 'requestor.e_mail:"{email}"'), [
            '{employee_id}' => self::quote($employee->id),
            '{email}' => self::quote($employee->email),
            '{login}' => self::quote($employee->login),
        ]);
        $data = $this->getJson('requests', [
            'max_rows' => (int) ($this->easyvistaOptions['nb_max_mes_demandes'] ?? 100),
            'sort' => 'submit_date_ut+desc',
            'fields' => $this->ticketFields(),
            'search' => $search,
        ]);

        return array_values(array_map(fn (array $r) => $this->toTicket($r), array_filter($data['records'] ?? [], 'is_array')));
    }

    public function getTicket(string $number): ?Ticket
    {
        try {
            $data = $this->getJson(sprintf('requests/%s', rawurlencode($number)), ['fields' => $this->ticketFields()]);
        } catch (EasyVistaException $e) {
            if (404 === $e->getStatusCode()) {
                return null;
            }
            throw $e;
        }

        return [] === $data ? null : $this->toTicket($data);
    }

    public function addMessage(string $number, string $message, Employee $author): void
    {
        $type = (string) ($this->easyvistaOptions['type_action_message'] ?? '');
        if ('' === $type) {
            throw new EasyVistaException('L\'envoi de message n\'est pas configuré (type_action_message).');
        }
        $this->request('POST', sprintf('requests/%s/actions', rawurlencode($number)), ['json' => ['action' => [
            'action_type_id' => $type,
            'description' => sprintf('Message de %s via Calipso', $author->name),
            'comment' => $message,
        ]]]);
    }

    public function findCatalogCode(string $title, ?string $path = null): ?string
    {
        $lang = $this->lang();
        $search = null !== $path
            ? sprintf('catalog_requests_path.sd_catalog_path_%s:"%s"', $lang, self::quote($path))
            : sprintf('title_%s:"%s"', $lang, self::quote($title));

        $data = $this->getJson('catalog-requests', ['max_rows' => 10, 'fields' => 'code,title_'.$lang, 'search' => $search]);
        $wanted = TextNormalizer::key($title);
        $fallback = null;
        foreach ($data['records'] ?? [] as $record) {
            $code = (string) self::field($record, 'CODE');
            if ('' === $code) {
                continue;
            }
            if (TextNormalizer::key((string) self::field($record, 'TITLE_'.strtoupper($lang))) === $wanted) {
                return $code;
            }
            $fallback ??= $code;
        }

        return null !== $path ? $fallback : null;
    }

    public function listCatalogEntries(): iterable
    {
        $lang = $this->lang();
        $data = $this->getJson('catalog-requests', [
            'max_rows' => 100000,
            'fields' => sprintf('code,title_%s,catalog_request_path', $lang),
        ]);
        foreach ($data['records'] ?? [] as $record) {
            $code = (string) self::field($record, 'CODE');
            if ('' === $code) {
                continue;
            }
            yield [
                'code' => $code,
                'title' => (string) self::field($record, 'TITLE_'.strtoupper($lang)),
                'path' => (string) (self::field($record, 'CATALOG_REQUEST_PATH') ?? self::field($record, 'CATALOG_REQUESTS_PATH.SD_CATALOG_PATH_'.strtoupper($lang)) ?? ''),
            ];
        }
    }

    // ------------------------------------------------------------------

    private function ticketFields(): string
    {
        $lang = $this->lang();

        return sprintf('rfc_number,submit_date_ut,end_date_ut,status.status_%1$s,sd_catalog_path,recipient.last_name', $lang);
    }

    /** @param array<string, mixed> $record */
    private function toEmployee(array $record): Employee
    {
        return new Employee(
            (string) self::field($record, 'EMPLOYEE_ID'),
            (string) self::field($record, 'LOGIN'),
            (string) self::field($record, 'LAST_NAME'),
            (string) self::field($record, 'E_MAIL'),
            (string) self::field($record, 'PHONE_NUMBER'),
            (string) self::field($record, 'DEPARTMENT_PATH'),
            (string) self::field($record, 'LOCATION_PATH'),
        );
    }

    /** @param array<string, mixed> $record */
    private function toTicket(array $record): Ticket
    {
        $lang = strtoupper($this->lang());
        $statusLabel = (string) (self::field($record, 'STATUS.STATUS_'.$lang) ?? self::field($record, 'STATUS.STATUS_EN') ?? '');
        $catalogPath = (string) (self::field($record, 'SD_CATALOG_PATH') ?? self::field($record, 'INITIAL_SD_CATALOG_PATH') ?? '');
        $parts = array_values(array_filter(explode('/', $catalogPath), static fn ($p) => '' !== trim($p)));
        $number = (string) self::field($record, 'RFC_NUMBER');

        return new Ticket(
            $number,
            $parts ? (string) end($parts) : $number,
            self::date(self::field($record, 'SUBMIT_DATE_UT')),
            $statusLabel,
            $this->statusClass($statusLabel),
            self::date(self::field($record, 'END_DATE_UT')),
            $catalogPath,
            (string) (self::field($record, 'RECIPIENT.LAST_NAME') ?? ''),
        );
    }

    private function statusClass(string $label): string
    {
        $norm = TextNormalizer::norm($label);
        foreach (['ok' => Ticket::STATUS_DONE, 'wait' => Ticket::STATUS_WAIT] as $key => $status) {
            $pattern = (string) ($this->easyvistaOptions['statuts'][$key] ?? '');
            if ('' !== $pattern && '' !== $norm && preg_match('/'.$pattern.'/', $norm)) {
                return $status;
            }
        }

        return Ticket::STATUS_RUNNING;
    }

    private function lang(): string
    {
        return strtolower((string) ($this->easyvistaOptions['langue'] ?? '')) ?: 'fr';
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     */
    private function getJson(string $path, array $query = []): array
    {
        return $this->decode($this->request('GET', $path, ['query' => $query]));
    }

    /** @param array<string, mixed> $options */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        if ('' !== $this->token) {
            $options['auth_bearer'] = $this->token;
        } elseif ('' !== $this->login) {
            $options['auth_basic'] = [$this->login, $this->password];
        }

        try {
            $response = $this->client->request($method, ltrim($path, '/'), $options);
            $status = $response->getStatusCode();
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('EasyVista injoignable : {message}', ['message' => $e->getMessage(), 'path' => $path]);
            throw new EasyVistaException('EasyVista est injoignable pour le moment.', null, $e);
        }

        if ($status >= 400) {
            $body = $response->getContent(false);
            $error = json_decode($body, true);
            $detail = \is_array($error) ? (string) ($error['error'] ?? $error['message'] ?? $body) : $body;
            $this->logger->error('EasyVista {method} {path} : HTTP {status} {detail}', ['method' => $method, 'path' => $path, 'status' => $status, 'detail' => mb_substr($detail, 0, 500)]);
            $endpoint = $method.' /'.preg_replace('#/.*$#', '', ltrim($path, '/'));
            $message = match (true) {
                401 === $status => 'EasyVista a refusé l\'authentification du portail (identifiants ou jeton invalides).',
                // 403 : le compte est reconnu mais son profil n'autorise pas cet endpoint
                403 === $status => sprintf('Le profil du compte API n\'autorise pas %s (HTTP 403%s).', $endpoint, '' !== trim($detail) ? ' : '.mb_substr(trim($detail), 0, 120) : ''),
                404 === $status => 'Élément introuvable dans EasyVista.',
                default => sprintf('EasyVista a renvoyé une erreur (%d) sur %s.', $status, $endpoint),
            };
            throw new EasyVistaException($message, $status);
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $content = $response->getContent(false);
        if ('' === trim($content)) {
            return [];
        }
        $data = json_decode($content, true);
        if (!\is_array($data)) {
            throw new EasyVistaException('Réponse EasyVista illisible.');
        }

        return $data;
    }

    /**
     * Lecture d'un champ, insensible à la casse, avec notation pointée pour les objets imbriqués.
     *
     * @param array<string, mixed> $record
     */
    private static function field(array $record, string $path): mixed
    {
        $value = $record;
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($value)) {
                return null;
            }
            $found = null;
            foreach ($value as $k => $v) {
                if (0 === strcasecmp((string) $k, $segment)) {
                    $found = $v;
                    break;
                }
            }
            if (null === $found) {
                return null;
            }
            $value = $found;
        }

        return \is_array($value) ? null : $value;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }
        foreach (['Y-m-d\TH:i:s.u\Z', 'Y-m-d\TH:i:s\Z', 'Y-m-d H:i:s', 'm/d/Y H:i:s', 'd/m/Y H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
            if (false !== $date) {
                return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            }
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function quote(string $value): string
    {
        return str_replace('"', '', $value);
    }
}
