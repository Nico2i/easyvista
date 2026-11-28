<?php

namespace App\Service;

use App\Catalog\Catalog;
use App\EasyVista\CatalogCodeResolver;
use App\EasyVista\Dto\Employee;
use App\EasyVista\Dto\Ticket;
use App\EasyVista\Dto\TicketDetail;
use App\EasyVista\Dto\TicketEvent;
use App\Text\TextNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prépare l'affichage du panneau de détail d'une demande : barre d'avancement,
 * historique (actions EasyVista + étapes à venir), encadré de situation, lien pour recommencer.
 */
class TicketView
{
    private const ICONS = [
        'envoi' => 'send', 'prise_en_compte' => 'inbox', 'affectation' => 'users', 'validation' => 'clock',
        'message' => 'chat', 'intervention' => 'wrench', 'resolution' => 'check', 'cloture' => 'flag',
    ];

    /** @param array<string, mixed> $easyvistaOptions */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly CatalogCodeResolver $codes,
        #[Autowire('%calipso.easyvista%')] private readonly array $easyvistaOptions,
    ) {
    }

    /**
     * @param list<TicketEvent> $events
     *
     * @return array<string, mixed>
     */
    public function build(TicketDetail $detail, array $events, Employee $viewer): array
    {
        $t = $detail->ticket;
        $classified = array_map(fn (TicketEvent $e) => ['event' => $e, 'kind' => $this->kind($e)], $events);

        return [
            'steps' => $this->steps($t, $classified),
            'timeline' => $this->timeline($detail, $classified, $viewer),
            'callout' => $this->callout($t, $classified),
            'again' => $this->again($t),
            'easyvistaUrl' => $this->easyvistaUrl($t->number),
            'subject' => $this->subjectLabel($t),
        ];
    }

    /** Catégorie d'une action, d'après son type et son libellé (config « types_actions »). */
    public function kind(TicketEvent $e): string
    {
        $text = TextNormalizer::norm($e->type.' '.$e->label);
        foreach ((array) ($this->easyvistaOptions['types_actions'] ?? []) as $kind => $pattern) {
            if ('' !== (string) $pattern && preg_match('/'.$pattern.'/', $text)) {
                return (string) $kind;
            }
        }

        return 'intervention';
    }

    /**
     * @param list<array{event: TicketEvent, kind: string}> $events
     *
     * @return list<array{label: string, detail: string, state: string}>
     */
    private function steps(Ticket $t, array $events): array
    {
        $first = static function (array $kinds) use ($events): ?TicketEvent {
            foreach ($events as $e) {
                if (\in_array($e['kind'], $kinds, true)) {
                    return $e['event'];
                }
            }

            return null;
        };
        $fmt = static fn (?\DateTimeImmutable $d): string => $d ? $d->format('d/m H:i') : '';
        $done = Ticket::STATUS_DONE === $t->status;

        $steps = [['label' => 'Envoyée', 'detail' => $fmt($t->submittedAt), 'state' => 'on']];
        if ($t->isIncident()) {
            $taken = $first(['prise_en_compte', 'affectation', 'intervention', 'message', 'resolution']);
            $steps[] = ['label' => 'Prise en charge', 'detail' => $taken ? $fmt($taken->startedAt) : 'à venir', 'state' => $taken || $done || Ticket::STATUS_RUNNING === $t->status ? 'on' : 'cur'];
        } else {
            $validation = $first(['validation']);
            $waiting = Ticket::STATUS_WAIT === $t->status;
            $steps[] = ['label' => 'Validation', 'detail' => $waiting ? 'en attente' : ($validation?->endedAt ? $fmt($validation->endedAt) : 'validée'), 'state' => $waiting ? 'cur' : 'on'];
        }
        $steps[] = ['label' => 'Traitement', 'detail' => $done ? 'fait' : (Ticket::STATUS_RUNNING === $t->status ? 'en cours' : ''), 'state' => $done ? 'on' : (Ticket::STATUS_RUNNING === $t->status ? 'cur' : '')];
        $steps[] = ['label' => 'Terminée', 'detail' => $done ? $fmt($t->closedAt) : '', 'state' => $done ? 'on' : ''];

        // Une étape « en cours » à la fois : la première non terminée
        $seenCurrent = false;
        foreach ($steps as &$s) {
            if ('cur' === $s['state']) {
                if ($seenCurrent) {
                    $s['state'] = '';
                }
                $seenCurrent = true;
            }
        }
        unset($s);

        return $steps;
    }

    /**
     * @param list<array{event: TicketEvent, kind: string}> $events
     *
     * @return list<array<string, mixed>>
     */
    private function timeline(TicketDetail $detail, array $events, Employee $viewer): array
    {
        $t = $detail->ticket;
        $isViewer = static fn (string $id, string $name): bool => ('' !== $id && $id === $viewer->id) || ('' !== $name && 0 === strcasecmp($name, $viewer->name));
        $requestorIsViewer = null === $detail->requestor || $isViewer($detail->requestor->id, $detail->requestor->name);

        $items = [[
            'kind' => 'envoi', 'icon' => self::ICONS['envoi'], 'color' => 'me',
            'title' => $t->isIncident() ? 'Signalement envoyé' : 'Demande envoyée',
            'at' => $t->submittedAt, 'who' => $requestorIsViewer ? 'Vous' : $detail->requestor->name,
            'message' => '', 'now' => false, 'future' => false, 'mine' => false,
        ]];

        foreach ($events as ['event' => $e, 'kind' => $kind]) {
            $mine = $isViewer($e->authorId, $e->authorName);
            $open = $e->isOpen() && Ticket::STATUS_DONE !== $t->status;
            $items[] = [
                'kind' => $kind,
                'icon' => self::ICONS[$kind] ?? 'wrench',
                'color' => $mine ? 'me' : (\in_array($kind, ['resolution', 'cloture'], true) ? 'ok' : ('validation' === $kind && $open ? 'wait' : 'acc')),
                'title' => $mine && 'message' === $kind ? 'Votre message' : ('' !== $e->label ? $e->label : ($e->type ?: 'Action')),
                'at' => $e->startedAt,
                'who' => $mine ? 'Vous' : $e->authorName,
                'message' => $e->comment,
                'now' => $open,
                'future' => false,
                'mine' => $mine,
                'type' => $e->type,
            ];
        }

        if (Ticket::STATUS_DONE !== $t->status) {
            $future = $t->isIncident()
                ? ['Résolution et clôture']
                : (Ticket::STATUS_WAIT === $t->status ? ['Traitement après validation', 'Livraison et clôture'] : ['Livraison et clôture']);
            foreach ($future as $label) {
                $items[] = ['kind' => 'futur', 'icon' => 'flag', 'color' => '', 'title' => $label, 'at' => null, 'who' => '', 'message' => '', 'now' => false, 'future' => true, 'mine' => false];
            }
        }

        return $items;
    }

    /**
     * @param list<array{event: TicketEvent, kind: string}> $events
     *
     * @return array{type: string, title: string, text: string}|null
     */
    private function callout(Ticket $t, array $events): ?array
    {
        if (Ticket::STATUS_WAIT === $t->status) {
            $validation = null;
            foreach ($events as $e) {
                if ('validation' === $e['kind'] && $e['event']->isOpen()) {
                    $validation = $e['event'];
                }
            }
            $since = $validation?->startedAt ?? $t->submittedAt;
            $days = $since ? (int) $since->diff(new \DateTimeImmutable())->days : 0;
            $who = $validation && '' !== $validation->authorName ? 'l\'accord de '.$validation->authorName : 'une validation';

            return [
                'type' => 'wait',
                'title' => 'En attente de validation',
                'text' => sprintf('Votre demande attend %s%s.', $who, $days > 0 ? sprintf(' depuis %d jour%s', $days, $days > 1 ? 's' : '') : ''),
            ];
        }
        if (Ticket::STATUS_DONE === $t->status) {
            $resolution = '';
            foreach ($events as $e) {
                if (\in_array($e['kind'], ['resolution', 'cloture'], true) && '' !== $e['event']->comment) {
                    $resolution = $e['event']->comment;
                }
            }

            return [
                'type' => 'ok',
                'title' => $t->isIncident() ? 'Résolu'.($t->closedAt ? ' le '.$t->closedAt->format('d/m/Y') : '') : 'Terminée'.($t->closedAt ? ' le '.$t->closedAt->format('d/m/Y') : ''),
                'text' => $resolution,
            ];
        }

        return null;
    }

    /**
     * Lien pour refaire la même chose quand c'est terminé.
     *
     * @return array{label: string, route: string, params: array<string, string>}|null
     */
    private function again(Ticket $t): ?array
    {
        if (Ticket::STATUS_DONE !== $t->status) {
            return null;
        }
        if ($t->isIncident()) {
            $subject = $this->subjectFromPath($t->catalogPath);

            return ['label' => 'Le problème est revenu ? Signaler à nouveau', 'route' => 'app_incident', 'params' => $subject ? ['sujet' => $subject] : []];
        }
        if (null !== $this->catalog->service($t->label)) {
            return ['label' => 'Refaire cette demande', 'route' => 'app_request', 'params' => ['service' => $t->label]];
        }

        return null;
    }

    private function subjectFromPath(string $path): ?string
    {
        $prefix = $this->codes->incidentPrefix().'/';
        if ('' === $path || !str_starts_with($path, $prefix)) {
            return null;
        }
        $key = substr($path, \strlen($prefix));

        return null !== $this->catalog->incident($key) ? $key : null;
    }

    private function subjectLabel(Ticket $t): string
    {
        if ('' === $t->catalogPath) {
            return $t->label;
        }
        $parts = array_values(array_filter(explode('/', $t->catalogPath), static fn ($p) => '' !== trim($p)));

        return implode(' › ', \array_slice($parts, max(0, \count($parts) - 2)));
    }

    private function easyvistaUrl(string $number): string
    {
        $pattern = (string) ($this->easyvistaOptions['lien_ticket'] ?? '');

        return '' === $pattern ? '' : str_replace('{rfc}', rawurlencode($number), $pattern);
    }
}
