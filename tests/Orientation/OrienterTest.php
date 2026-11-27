<?php

namespace App\Tests\Orientation;

use App\Orientation\Orienter;
use App\Orientation\OrientationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le portage PHP doit orienter exactement comme la maquette validée.
 * tests/Fixtures/orientation-reference.json a été produit avec le moteur JavaScript de docs/maquette.
 */
final class OrienterTest extends KernelTestCase
{
    public static function referenceCases(): iterable
    {
        $cases = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/orientation-reference.json'), true, 512, \JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            yield $case['q'] => [$case];
        }
    }

    #[DataProvider('referenceCases')]
    public function testSameResultsAsPrototype(array $expected): void
    {
        $r = $this->orienter()->orient($expected['q']);

        self::assertSame($expected['mode'], $r->mode, 'lecture de la phrase');
        self::assertSame($expected['inc'], array_map(static fn ($o) => $o->name, $r->incidents), 'sujets d\'incident');
        self::assertSame($expected['svc'], array_map(static fn ($o) => $o->title, $r->services), 'services');
        self::assertSame($expected['acc'], array_map(static fn ($o) => $o->name, $r->access), 'accès');
        self::assertSame($expected['fam'], $r->families, 'rubriques');
        self::assertSame($expected['atype'], $r->accessTypes, 'types d\'accès');
        self::assertSame($expected['svcExact'], $r->exactService, 'service exact');
        self::assertSame($expected['accFirst'], $r->accessFirst, 'accès en premier');
    }

    public function testProblemPutsIncidentsFirst(): void
    {
        $r = $this->orienter()->orient('Mon imprimante n\'imprime plus');

        self::assertSame(OrientationResult::PROBLEM, $r->mode);
        self::assertSame('incidents', $r->groupOrder($r->mode)[0]);
    }

    public function testNeedPutsRequestsFirst(): void
    {
        $r = $this->orienter()->orient('Un stagiaire arrive lundi');

        self::assertSame(OrientationResult::NEED, $r->mode);
        self::assertSame('Déclarer l\'arrivée d\'un agent', $r->services[0]->title);
        self::assertNotSame('incidents', $r->groupOrder($r->mode)[0]);
    }

    public function testSuspiciousMailGoesToCybersecurity(): void
    {
        $r = $this->orienter()->orient('J\'ai reçu un mail suspect');

        self::assertSame('Cybersécurité', $r->incidents[0]->name);
    }

    public function testUnknownSentenceGivesNothing(): void
    {
        self::assertTrue($this->orienter()->orient('xyzzy plugh')->isEmpty());
    }

    private function orienter(): Orienter
    {
        self::bootKernel();

        return self::getContainer()->get(Orienter::class);
    }
}
