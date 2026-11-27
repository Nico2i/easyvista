<?php

namespace App\Command;

use App\EasyVista\EasyVistaClientInterface;
use App\EasyVista\Exception\EasyVistaException;
use App\Text\TextNormalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Télécharge le catalogue EasyVista (GET /catalog-requests) et écrit data/catalog-codes.json :
 * la correspondance intitulé / chemin → code catalogue utilisée à la création des demandes.
 * À relancer quand le catalogue évolue.
 */
#[AsCommand(name: 'calipso:catalogue:sync-codes', description: 'Récupère les codes catalogue depuis l\'API EasyVista')]
final class SyncCatalogCodesCommand extends Command
{
    public function __construct(
        private readonly EasyVistaClientInterface $easyVista,
        #[Autowire('%calipso.codes_file%')] private readonly string $codesFile,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->easyVista->isMock()) {
            $io->error('EASYVISTA_MODE=mock : passez en mode api (dans .env.local) pour synchroniser les codes.');

            return Command::FAILURE;
        }

        $titles = [];
        $paths = [];
        $n = 0;
        try {
            foreach ($this->easyVista->listCatalogEntries() as $entry) {
                ++$n;
                if ('' !== $entry['title']) {
                    $titles[TextNormalizer::key($entry['title'])] ??= $entry['code'];
                }
                if ('' !== $entry['path']) {
                    $paths[TextNormalizer::key($entry['path'])] ??= $entry['code'];
                    if ('' !== $entry['title'] && !str_ends_with($entry['path'], $entry['title'])) {
                        $paths[TextNormalizer::key($entry['path'].'/'.$entry['title'])] ??= $entry['code'];
                    }
                }
            }
        } catch (EasyVistaException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        file_put_contents($this->codesFile, json_encode([
            'generated' => date('c'),
            'titles' => $titles,
            'paths' => $paths,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

        $io->success(sprintf('%d entrées lues, %d intitulés et %d chemins enregistrés dans %s.', $n, \count($titles), \count($paths), $this->codesFile));

        return Command::SUCCESS;
    }
}
