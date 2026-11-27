<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Régénère data/catalogue.json à partir des exports CSV EasyVista (séparateur « ; », UTF-8).
 * Reprend la logique de docs/maquette/src/build_data.py.
 *
 * Colonnes attendues (comme pour la maquette) :
 *  - catalogue des incidents : 0 = nom du sujet, 1 = chemin complet, 2 et 3 = workflow/groupe ;
 *  - catalogue des demandes : 0 = intitulé, 2 = valideur, 3 = délai, 6 = quantité maximale.
 * L'option --colonne-code indique la colonne contenant le code catalogue (SD_CATALOG.CODE), si exportée.
 */
#[AsCommand(name: 'calipso:catalogue:import', description: 'Importe les catalogues EasyVista (CSV) dans data/catalogue.json')]
final class ImportCatalogueCommand extends Command
{
    private const ACCESS_PATTERNS = [
        ['/^Demander à acc[eéè]der (?:à |au |aux )(.*)$/u', 1],
        ["/^Demander la révocation d'un accès (?:à |au |aux )(.*)$/u", 2],
        ["/^Demander la modification d'un accès (?:à |au |aux )(.*)$/u", 4],
        ['/^Demander un r[eé]l[eé]v[eé] des accès (?:à |au |aux )(.*)$/u', 8],
    ];

    private const FAMILY_RULES = [
        ['resa', '/^(réserver|réservation|planifier une réunion|annuler une réservation|modifier une réservation)|salle de formation/u'],
        ['log', "/installation|^installer|référencement d'un nouveau logiciel|réforme d'un logiciel|bouquet/u"],
        ['mat', '/^(demander|emprunter) (un|une|des) .*(ordinateur|portable|écran|souris|clavier|casque|micro|smartphone|téléphone|imprimante|scanner|câble|carte|clef|sacoche|sac |valise|chargeur|kit|lecteur|galet|pieuvre|unité centrale|tablette|copieur)|réhausseur|ultra-portable|point d.impression|portable en estran/u'],
        ['four', '/^commander|courrier/u'],
        ['fin', '/sinistre|coriolis|mandat|véhicule|aiden/u'],
        ['rh', '/retraite|carrière|médaille|proche en cas|arrivée|départ|chef\.fe|qvt|ma photo|gestionnaire d.absence/u'],
        ['secu', '/certificat|firewall|bastion|téléintervenir|déblocage|informatique et libertés|zed|compte informatique|boite (aux lettres )?fonctionnelle|espace geco|espace projet|nouveau q:|service applicatif|restauration/u'],
        ['reception', '/réception|logistique/u'],
    ];

    public function __construct(
        #[Autowire('%calipso.catalogue_file%')] private readonly string $catalogueFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('incidents', InputArgument::REQUIRED, 'Export CSV du catalogue des incidents')
            ->addArgument('demandes', InputArgument::REQUIRED, 'Export CSV du catalogue des demandes de service')
            ->addOption('colonne-code', null, InputOption::VALUE_REQUIRED, 'Numéro de colonne (à partir de 0) du code catalogue dans les deux CSV')
            ->addOption('sortie', null, InputOption::VALUE_REQUIRED, 'Fichier produit', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $codeCol = null !== $input->getOption('colonne-code') ? (int) $input->getOption('colonne-code') : null;

        // Sujets d'incident
        $inc = [];
        $seen = [];
        foreach ($this->rows($input->getArgument('incidents')) as $x) {
            $n = self::n($x[0] ?? '');
            $p = self::n($x[1] ?? '');
            $parts = explode('/', $p);
            if (\count($parts) < 3 || isset($seen[$p])) {
                continue;
            }
            $seen[$p] = true;
            if (\in_array($n, ['Sujet par défaut', 'Connaissance', 'Front Office'], true)) {
                continue;
            }
            if ('' === trim($x[2] ?? '') && '' === trim($x[3] ?? '') && \in_array($n, ['Accès', 'Equipement central', 'Equipement de proximité', 'Logiciel', 'Serveur distant'], true)) {
                continue;
            }
            $family = ['Logiciel' => 'log', 'Equipement de proximité' => 'eqp', 'Equipement central' => 'eqc', 'Accès' => 'acc', 'Cybersécurité' => 'cyb'][$parts[2]] ?? 'autre';
            $row = ['n' => $n, 'p' => implode('/', \array_slice($parts, 2, -1)), 'f' => $family];
            if (null !== $codeCol && '' !== trim($x[$codeCol] ?? '')) {
                $row['c'] = trim($x[$codeCol]);
            }
            $inc[] = $row;
        }

        // Services et accès
        $rows = [];
        foreach ($this->rows($input->getArgument('demandes')) as $x) {
            $t = self::n($x[0] ?? '');
            if ('' !== $t && !isset($rows[$t])) {
                $rows[$t] = $x;
            }
        }
        $acc = [];
        $svc = [];
        foreach ($rows as $title => $x) {
            if (preg_match('/(laboratoire|\(Test\)|^Laboratoire$|^Clonage|- 2024$|Mandarine)/iu', $title)) {
                continue;
            }
            foreach (self::ACCESS_PATTERNS as [$pattern, $bit]) {
                if (preg_match($pattern, $title, $m)) {
                    $resource = preg_replace("/^(l'|la |le |les )/u", '', trim($m[1]));
                    $acc[$resource] ??= ['a' => 0, 'ti' => []];
                    $acc[$resource]['a'] |= $bit;
                    $acc[$resource]['ti'][(string) $bit] = $title;
                    continue 2;
                }
            }
            $item = ['t' => $title, 'd' => trim($x[3] ?? ''), 'g' => trim($x[2] ?? ''), 'q' => trim($x[6] ?? ''), 'f' => self::family($title)];
            if (null !== $codeCol && '' !== trim($x[$codeCol] ?? '')) {
                $item['c'] = trim($x[$codeCol]);
            }
            $svc[] = $item;
        }
        uksort($acc, static fn ($a, $b) => strcmp(mb_strtolower($a), mb_strtolower($b)));
        $accList = [];
        foreach ($acc as $r => $def) {
            $accList[] = ['r' => $r, 't' => self::resourceType($r), 'a' => $def['a'], 'ti' => $def['ti']];
        }

        $file = $input->getOption('sortie') ?? $this->catalogueFile;
        file_put_contents($file, json_encode(['inc' => $inc, 'svc' => $svc, 'acc' => $accList], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        $io->success(sprintf('%d sujets d\'incident, %d services, %d ressources d\'accès écrits dans %s.', \count($inc), \count($svc), \count($accList), $file));
        $io->note('Pensez à vider le cache : php bin/console cache:clear');

        return Command::SUCCESS;
    }

    /** @return iterable<list<string>> */
    private function rows(string $file): iterable
    {
        if (!is_readable($file)) {
            throw new \RuntimeException('Fichier illisible : '.$file);
        }
        $h = fopen($file, 'r');
        $first = true;
        while (false !== ($x = fgetcsv($h, null, ';', '"', ''))) {
            if ($first) {
                $first = false; // ligne d'en-tête

                continue;
            }
            if ([null] === $x || [] === $x) {
                continue;
            }
            yield array_map(static fn ($v) => (string) $v, $x);
        }
        fclose($h);
    }

    private static function n(string $v): string
    {
        $v = preg_replace('/^\xEF\xBB\xBF/', '', $v) ?? $v;
        $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;

        return trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
    }

    private static function family(string $title): string
    {
        $t = mb_strtolower($title);
        foreach (self::FAMILY_RULES as [$family, $pattern]) {
            if (preg_match($pattern, $t)) {
                return $family;
            }
        }

        return 'proj';
    }

    private static function resourceType(string $r): string
    {
        return match (true) {
            str_contains($r, '@') => 'bal',
            str_starts_with($r, 'Q:') => 'q',
            str_starts_with(mb_strtolower($r), 'espace projet') => 'proj',
            1 === preg_match('/univers BO|tableau(x)? de bord|Business Object/iu', $r) => 'bo',
            default => 'app',
        };
    }
}
