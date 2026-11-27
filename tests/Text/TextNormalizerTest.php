<?php

namespace App\Tests\Text;

use App\Text\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class TextNormalizerTest extends TestCase
{
    public function testNormRemovesAccentsAndPunctuation(): void
    {
        self::assertSame('mon ecran n affiche plus rien', TextNormalizer::norm("Mon écran n'affiche plus rien !"));
        self::assertSame('mddgrst@cotesdarmor fr', TextNormalizer::norm('_MDDGRST@cotesdarmor.fr'));
    }

    public function testKeyIgnoresArticles(): void
    {
        self::assertSame(TextNormalizer::key('Demander à accéder à la GECO'), TextNormalizer::key('Demander à accéder au GECO'));
    }
}
