<?php

namespace App\Tests\Security;

use App\EasyVista\Dto\Employee;
use App\EasyVista\EasyVistaClientInterface;
use App\EasyVista\Exception\EasyVistaException;
use App\Security\PortalAuthenticator;
use App\Security\PortalUser;
use App\Security\PortalUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Twig\Environment;

final class PortalAuthenticatorTest extends KernelTestCase
{
    public function testUnknownAgentShowsTheRealReason(): void
    {
        $auth = $this->authenticator(static fn () => null);
        $request = $this->request('CG22\\jdupont');

        $exception = $this->failToAuthenticate($auth, $request);
        $html = (string) $auth->onAuthenticationFailure($request, $exception)->getContent();

        self::assertStringContainsString('n&#039;est pas connu d&#039;EasyVista', $html);
        self::assertStringContainsString('jdupont', $html);
        self::assertStringNotContainsString('Votre identification a échoué', $html);
    }

    public function testApiErrorIsShown(): void
    {
        $auth = $this->authenticator(static fn () => throw new EasyVistaException('Le profil du compte API n\'autorise pas GET /employees (HTTP 403).', 403));
        $request = $this->request('jdupont');

        $html = (string) $auth->onAuthenticationFailure($request, $this->failToAuthenticate($auth, $request))->getContent();

        self::assertStringContainsString('autorise pas GET /employees', $html);
    }

    public function testLoginWithDomainIsTriedWhenShortLoginIsUnknown(): void
    {
        $tried = [];
        $auth = $this->authenticator(static function (string $login) use (&$tried) {
            $tried[] = $login;

            return 'CG22\\jdupont' === $login ? new Employee('7', $login, 'DUPONT Jean') : null;
        });

        $user = $auth->authenticate($this->request('CG22\\jdupont'))->getBadge(UserBadge::class)->getUser();

        self::assertInstanceOf(PortalUser::class, $user);
        self::assertSame(['jdupont', 'CG22\\jdupont'], $tried);
    }

    private function authenticator(callable $find): PortalAuthenticator
    {
        self::bootKernel();
        $client = $this->createStub(EasyVistaClientInterface::class);
        $client->method('findEmployeeByLogin')->willReturnCallback($find);

        return new PortalAuthenticator(self::getContainer()->get(Environment::class), new PortalUserProvider($client), 'REMOTE_USER', '', true);
    }

    private function request(string $remoteUser): Request
    {
        return new Request(server: ['REMOTE_USER' => $remoteUser]);
    }

    private function failToAuthenticate(PortalAuthenticator $auth, Request $request): AuthenticationException
    {
        try {
            $auth->authenticate($request)->getBadge(UserBadge::class)->getUser();
        } catch (AuthenticationException $e) {
            return $e;
        }
        self::fail('L\'authentification aurait dû échouer.');
    }
}
