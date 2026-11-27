<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Twig\Environment;

/**
 * Authentification unique : le serveur web (IIS avec authentification Windows, Apache mod_auth_kerb,
 * reverse proxy…) fournit le login de l'agent dans une variable serveur (REMOTE_USER par défaut).
 * Le portail n'affiche jamais de formulaire de connexion.
 *
 * En développement, CALIPSO_DEV_LOGIN remplace le SSO.
 */
final class PortalAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly string $serverVar = 'REMOTE_USER',
        private readonly string $devLogin = '',
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Déjà authentifié dans cette session : rien à faire
        if ($request->hasPreviousSession() && $request->getSession()->has('_security_main')) {
            return false;
        }

        return null !== $this->loginFrom($request);
    }

    public function authenticate(Request $request): Passport
    {
        return new SelfValidatingPassport(new UserBadge((string) $this->loginFrom($request)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $reason = match (true) {
            $exception instanceof UserNotFoundException => sprintf('Votre compte (%s) n\'est pas connu d\'EasyVista.', $exception->getUserIdentifier()),
            $exception instanceof AuthenticationServiceException => 'EasyVista est momentanément indisponible. Réessayez dans quelques minutes.',
            default => 'Votre identification a échoué.',
        };

        return $this->page($reason, Response::HTTP_FORBIDDEN);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->page('Le portail n\'a pas pu récupérer votre identifiant Windows. Ouvrez Calipso depuis un poste du réseau départemental.', Response::HTTP_UNAUTHORIZED);
    }

    /** Login sans domaine : « CG22\jdupont » ou « jdupont@cg22.local » donnent « jdupont ». */
    public function loginFrom(Request $request): ?string
    {
        $raw = (string) ($request->server->get($this->serverVar) ?? $request->server->get('REDIRECT_'.$this->serverVar) ?? '');
        if ('' === trim($raw)) {
            $raw = $this->devLogin;
        }
        $raw = trim($raw);
        if ('' === $raw) {
            return null;
        }
        if (str_contains($raw, '\\')) {
            $raw = substr($raw, strrpos($raw, '\\') + 1);
        }
        if (str_contains($raw, '@')) {
            $raw = substr($raw, 0, strpos($raw, '@'));
        }

        return '' === $raw ? null : $raw;
    }

    private function page(string $reason, int $status): Response
    {
        return new Response($this->twig->render('security/denied.html.twig', ['reason' => $reason]), $status);
    }
}
