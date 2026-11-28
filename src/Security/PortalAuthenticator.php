<?php

namespace App\Security;

use App\EasyVista\Exception\EasyVistaException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
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
 *
 * Les erreurs sont levées en CustomUserMessageAuthenticationException : contrairement à
 * « utilisateur introuvable », Symfony ne les masque pas, et l'agent voit la vraie raison.
 */
final class PortalAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly PortalUserProvider $users,
        private readonly string $serverVar = 'REMOTE_USER',
        private readonly string $devLogin = '',
        #[Autowire('%kernel.debug%')] private readonly bool $debug = false,
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
        $login = (string) $this->loginFrom($request);
        // Login sans domaine, puis tel que reçu (« DOMAINE\login ») : EasyVista peut stocker l'un ou l'autre
        $candidates = array_values(array_unique(array_filter([$login, $this->rawLoginFrom($request)])));

        return new SelfValidatingPassport(new UserBadge($login, function () use ($login, $candidates): PortalUser {
            try {
                foreach ($candidates as $candidate) {
                    if ($user = $this->users->findUser($candidate)) {
                        return $user;
                    }
                }
            } catch (EasyVistaException $e) {
                throw new CustomUserMessageAuthenticationException('Le portail n\'arrive pas à interroger EasyVista : {detail}', ['{detail}' => $e->getMessage()], 0, $e);
            }

            throw new CustomUserMessageAuthenticationException('Votre compte « {login} » n\'est pas connu d\'EasyVista.', ['{login}' => implode(' » ou « ', $candidates)]);
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $reason = strtr($exception->getMessageKey(), $exception->getMessageData());
        if (!$exception instanceof CustomUserMessageAuthenticationException) {
            $reason = 'Votre identification a échoué.';
        }

        return $this->page($reason, Response::HTTP_FORBIDDEN, $this->loginFrom($request));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->page('Le portail n\'a pas pu récupérer votre identifiant Windows. Ouvrez Calipso depuis un poste du réseau départemental.', Response::HTTP_UNAUTHORIZED, null);
    }

    /** Login sans domaine : « CG22\jdupont » ou « jdupont@cg22.local » donnent « jdupont ». */
    public function loginFrom(Request $request): ?string
    {
        $raw = (string) $this->rawLoginFrom($request);
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

    /** Login tel que fourni par le serveur web (ou CALIPSO_DEV_LOGIN). */
    private function rawLoginFrom(Request $request): ?string
    {
        $raw = trim((string) ($request->server->get($this->serverVar) ?? $request->server->get('REDIRECT_'.$this->serverVar) ?? ''));
        if ('' === $raw) {
            $raw = trim($this->devLogin);
        }

        return '' === $raw ? null : $raw;
    }

    private function page(string $reason, int $status, ?string $login): Response
    {
        return new Response($this->twig->render('security/denied.html.twig', [
            'reason' => $reason,
            'login' => $login,
            'debug' => $this->debug,
        ]), $status);
    }
}
