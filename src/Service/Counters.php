<?php

namespace App\Service;

use App\EasyVista\EasyVistaClientInterface;
use App\EasyVista\Exception\EasyVistaException;
use App\Security\PortalUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Compteurs affichés dans l'en-tête et le panneau « Mon activité ».
 * Mis en cache 2 minutes par agent ; null si EasyVista ne répond pas.
 */
class Counters
{
    /** @var array{open: int, problems: int, approvals: ?int, actions: ?int, groupActions: ?int}|null */
    private ?array $memo = null;

    public function __construct(
        private readonly EasyVistaClientInterface $easyVista,
        private readonly Security $security,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @return array{open: int, problems: int, approvals: ?int, actions: ?int, groupActions: ?int}|null */
    public function get(): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof PortalUser) {
            return null;
        }
        if (null !== $this->memo) {
            return $this->memo;
        }

        try {
            $key = 'calipso_counters_'.($this->easyVista->isMock() ? 'mock_' : 'api_').md5($user->getUserIdentifier());

            return $this->memo = $this->cache->get($key, function (ItemInterface $item) use ($user): array {
                $item->expiresAfter(120);
                $open = array_filter($this->easyVista->listTickets($user->employee), static fn ($t) => $t->isOpen());

                return [
                    'open' => \count($open),
                    'problems' => \count(array_filter($open, static fn ($t) => $t->isIncident())),
                    // Approbations et actions : non exposées simplement par l'API REST ;
                    // affichées seulement en démonstration (voir README, « Pistes »).
                    'approvals' => $this->easyVista->isMock() ? 1 : null,
                    'actions' => $this->easyVista->isMock() ? 11 : null,
                    'groupActions' => $this->easyVista->isMock() ? 20 : null,
                ];
            });
        } catch (EasyVistaException) {
            return null;
        }
    }

    /** À appeler après un envoi pour que les compteurs se mettent à jour. */
    public function reset(PortalUser $user): void
    {
        $this->memo = null;
        $this->cache->delete('calipso_counters_'.($this->easyVista->isMock() ? 'mock_' : 'api_').md5($user->getUserIdentifier()));
    }
}
