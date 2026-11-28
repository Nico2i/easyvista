<?php

namespace App\Security;

use App\EasyVista\EasyVistaClientInterface;
use App\EasyVista\Exception\EasyVistaException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Charge l'agent depuis EasyVista à partir de son login Windows.
 *
 * @implements UserProviderInterface<PortalUser>
 */
final class PortalUserProvider implements UserProviderInterface
{
    public function __construct(private readonly EasyVistaClientInterface $easyVista)
    {
    }

    /**
     * @throws EasyVistaException si EasyVista ne répond pas ou refuse l'appel
     */
    public function findUser(string $login): ?PortalUser
    {
        $employee = $this->easyVista->findEmployeeByLogin($login);

        return null === $employee ? null : new PortalUser($employee);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        try {
            $user = $this->findUser($identifier);
        } catch (EasyVistaException $e) {
            throw new AuthenticationServiceException($e->getMessage(), 0, $e);
        }
        if (null === $user) {
            $e = new UserNotFoundException(sprintf('Agent « %s » introuvable dans EasyVista.', $identifier));
            $e->setUserIdentifier($identifier);
            throw $e;
        }

        return $user;
    }

    /** L'agent est conservé en session : pas de nouvel appel à EasyVista à chaque page. */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof PortalUser) {
            throw new UnsupportedUserException(sprintf('Utilisateur non pris en charge : %s', $user::class));
        }

        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return PortalUser::class === $class || is_subclass_of($class, PortalUser::class);
    }
}
