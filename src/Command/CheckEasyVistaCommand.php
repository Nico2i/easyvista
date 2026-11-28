<?php

namespace App\Command;

use App\Catalog\Catalog;
use App\EasyVista\CatalogCodeResolver;
use App\EasyVista\EasyVistaClientInterface;
use App\EasyVista\Exception\EasyVistaException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vérifie la connexion à EasyVista, la recherche d'un agent et la résolution des codes catalogue,
 * sans rien créer. À lancer en premier sur le poste de travail.
 */
#[AsCommand(name: 'calipso:easyvista:check', description: 'Teste la connexion à l\'API EasyVista (lecture seule)')]
final class CheckEasyVistaCommand extends Command
{
    public function __construct(
        private readonly EasyVistaClientInterface $easyVista,
        private readonly CatalogCodeResolver $codes,
        private readonly Catalog $catalog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('login', InputArgument::OPTIONAL, 'Login Windows d\'un agent à rechercher')
            ->addOption('codes', null, InputOption::VALUE_REQUIRED, 'Nombre de services dont on vérifie le code catalogue', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Vérification EasyVista '.($this->easyVista->isMock() ? '(mode démonstration)' : '(API)'));
        $ok = true;

        // 1. Droits du compte API, endpoint par endpoint (lecture seule)
        $access = $this->easyVista->checkAccess();
        $io->section('Droits du compte API (lecture)');
        $io->table(['Endpoint', 'Utilisé pour', 'Résultat'], array_map(static fn (array $a) => [
            $a['endpoint'],
            $a['usage'],
            $a['ok'] ? '<info>OK</info> · '.$a['detail'] : '<error>'.$a['detail'].'</error>',
        ], $access));

        $byEndpoint = array_column($access, 'ok', 'endpoint');
        if (false === ($byEndpoint['GET /employees'] ?? true)) {
            $io->error('Sans accès à GET /employees, le portail ne peut pas identifier les agents. Vérifiez l\'URL, les identifiants et le profil du compte API.');

            return Command::FAILURE;
        }
        foreach (['GET /requests', 'GET /catalog-requests'] as $required) {
            if (false === ($byEndpoint[$required] ?? true)) {
                $ok = false;
            }
        }
        $io->note([
            'Non testés ici (ils créeraient des données) : POST /requests (création), POST /requests/{rfc}/documents (pièces jointes) et POST /requests/{rfc}/actions (messages).',
            'Le profil du compte API doit aussi les autoriser.',
        ]);

        if ($login = $input->getArgument('login')) {
            try {
                $e = $this->easyVista->findEmployeeByLogin($login);
                $e ? $io->writeln(sprintf('Agent %s : <info>%s</info> (%s, id %s)', $login, $e->name, $e->email ?: 'sans e-mail', $e->id))
                   : $io->warning('Agent introuvable : '.$login);
                if ($e) {
                    $tickets = $this->easyVista->listTickets($e);
                    $io->writeln(sprintf('Mes demandes : <info>%d</info> trouvée(s)', \count($tickets)));
                    foreach (\array_slice($tickets, 0, 5) as $t) {
                        $io->writeln(sprintf('  %s  %s  [%s]', $t->number, $t->label, $t->statusLabel));
                    }
                }
            } catch (EasyVistaException $ex) {
                $io->error($ex->getMessage());
                $ok = false;
            }
        }

        $rows = [];
        try {
            $rows[] = ['Sujet par défaut (incident)', $this->codes->forIncident(null)];
        } catch (EasyVistaException $e) {
            $rows[] = ['Sujet par défaut (incident)', '<error>'.$e->getMessage().'</error>'];
            $ok = false;
        }
        foreach (\array_slice($this->catalog->incidents(), 0, 3) as $s) {
            try {
                $rows[] = [$s->catalogPath($this->codes->incidentPrefix()), $this->codes->forIncident($s)];
            } catch (EasyVistaException $e) {
                $rows[] = [$s->catalogPath($this->codes->incidentPrefix()), '<error>introuvable</error>'];
                $ok = false;
            }
        }
        foreach (\array_slice(array_values($this->catalog->services()), 0, (int) $input->getOption('codes')) as $s) {
            try {
                $rows[] = [$s->title, $this->codes->forService($s->title, $s->code)];
            } catch (EasyVistaException $e) {
                $rows[] = [$s->title, '<error>introuvable</error>'];
                $ok = false;
            }
        }
        $io->table(['Élément du catalogue', 'Code EasyVista'], $rows);

        $ok ? $io->success('Tout est en ordre.') : $io->warning('Des points sont à corriger (voir README, « Mise en service »).');

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
