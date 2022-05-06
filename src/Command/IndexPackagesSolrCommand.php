<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

//use Algolia\AlgoliaSearch\SearchClient;
use App\Entity\Package;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use Solarium\Core\Query\DocumentInterface;
use Solarium\QueryType\Update\Query\Query;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use App\Service\Locker;
use Predis\Client;
use Symfony\Component\Console\Command\Command;

class IndexPackagesSolrCommand extends Command
{
    use \App\Util\DoctrineTrait;

    private \Solarium\Client $solarium;
    private Locker $locker;
    private ManagerRegistry $doctrine;
    private Client $redis;
    private DownloadManager $downloadManager;
    private FavoriteManager $favoriteManager;
    private string $algoliaIndexName;
    private string $cacheDir;

    public function __construct( \Nelmio\SolariumBundle\ClientRegistry $solariumReg, Locker $locker, ManagerRegistry $doctrine, Client $redis, DownloadManager $downloadManager, FavoriteManager $favoriteManager, string $algoliaIndexName, string $cacheDir)
    {
        $this->solarium = $solariumReg->getClient();
        $this->locker = $locker;
        $this->doctrine = $doctrine;
        $this->redis = $redis;
        $this->downloadManager = $downloadManager;
        $this->favoriteManager = $favoriteManager;
        $this->algoliaIndexName = $algoliaIndexName;
        $this->cacheDir = $cacheDir;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('packagist:solr:index')
            ->setDefinition([
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a re-indexing of all packages'),
                new InputOption('all', null, InputOption::VALUE_NONE, 'Index all packages without clearing the index first'),
                new InputArgument('package', InputArgument::OPTIONAL, 'Package name to index'),
            ])
            ->setDescription('Indexes packages in Solr')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('verbose');
        $force = $input->getOption('force');
        $indexAll = $input->getOption('all');
        $package = $input->getArgument('package');

        $deployLock = $this->cacheDir.'/deploy.globallock';
        if (file_exists($deployLock)) {
            if ($verbose) {
                $output->writeln('Aborting, '.$deployLock.' file present');
            }
            return 0;
        }

        $lockAcquired = $this->locker->lockCommand($this->getName());
        if (!$lockAcquired) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another task is running already');
            }
            return 0;
        }

        //$index = $this->algolia->initIndex($this->algoliaIndexName);

        if ($package) {
            $packages = [['id' => $this->getEM()->getRepository(Package::class)->findOneBy(['name' => $package])->getId()]];
        } elseif ($force || $indexAll) {
            $packages = $this->getEM()->getConnection()->fetchAllAssociative('SELECT id FROM package ORDER BY id ASC');
            $this->getEM()->getConnection()->executeQuery('UPDATE package SET indexedAt = NULL');
        } else {
            $packages = $this->getEM()->getRepository(Package::class)->getStalePackagesForIndexing();
        }

        $ids = [];
        foreach ($packages as $row) {
            $ids[] = $row['id'];
        }

        $solarium = $this->solarium;
        // clear index before a full-update
        if ($force && !$package) {
            if ($verbose) {
                $output->writeln('Deleting existing index');
            }
            $update = $solarium->createUpdate();
            $update->addDeleteQuery('*:*');
            $update->addCommit();

            $solarium->update($update);

           // $index->clear();
        }

        $total = count($ids);
        $current = 0;

        // update package index
        while ($ids) {
            $indexTime = new \DateTime;
            $idsSlice = array_splice($ids, 0, 50);
            $packages = $this->getEM()->getRepository(Package::class)->findBy(['id' => $idsSlice]);
            $update = $solarium->createUpdate();


            $idsToUpdate = [];
            $records = [];

            foreach ($packages as $package) {
                $current++;
                if ($verbose) {
                    $output->writeln('['.sprintf('%'.strlen($total).'d', $current).'/'.$total.'] Indexing '.$package->getName());
                }

                // delete spam packages from the search index
                if ($package->isAbandoned() && $package->getReplacementPackage() === 'spam/spam') {
                        $update->addDeleteById( $package->getId());
                        $update->addCommit(); ;
                        $idsToUpdate[] = $package->getId();
                        continue;
                }

                try {
                    $document = $update->createDocument();

                    $tags = $this->getTags($package);
                    $this->updateDocumentFromPackage($document, $package, $tags);
                    $update->addDocument($document);

                    //$records[] = $this->packageToSearchableArray($package, $tags);
                    $idsToUpdate[] = $package->getId();
                } catch (\Exception $e) {
                    $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().'.</error>');

                    continue;
                }

                $providers = $this->getProviders($package);
                foreach ($providers as $provided) {
                    try {
                        $this->createSearchableProvider($update, $provided['packageName']);
                    } catch (\Exception $e) {
                        $output->writeln('<error>'.get_class($e).': '.$e->getMessage().', skipping package '.$package->getName().':provide:'.$provided.'</error>');
                    }
                }
            }

            try {
                $update->addCommit();
                $solarium->update($update);
            } catch (\Exception $e) {
                $output->writeln('<error>'.get_class($e).': '.$e->getMessage().', occurred while processing packages: '.implode(',', $idsSlice).'</error>');
                continue;
            }

            $this->getEM()->clear();
            unset($packages);

            if ($verbose) {
                $output->writeln('Updating package indexedAt column');
            }

            $this->updateIndexedAt($idsToUpdate, $indexTime->format('Y-m-d H:i:s'));
        }

        $this->locker->unlockCommand($this->getName());

        return 0;
    }

    private function packageToSearchableArray(Package $package, array $tags)
    {
        $faversCount = $this->favoriteManager->getFaverCount($package);
        $downloads = $this->downloadManager->getDownloads($package);
        $downloadsLog = $downloads['monthly'] > 0 ? log($downloads['monthly'], 10) : 0;
        $starsLog = $package->getGitHubStars() > 0 ? log($package->getGitHubStars(), 10) : 0;
        $popularity = round($downloadsLog + $starsLog);
        $trendiness = $this->redis->zscore('downloads:trending', $package->getId());

        $record = [
            'id' => $package->getId(),
            'objectID' => $package->getName(),
            'name' => $package->getName(),
            //'package_organisation' => $package->getVendor(),
            'package_name' => $package->getPackageName(),
            'description' => preg_replace('{[\x00-\x1f]+}u', '', strip_tags($package->getDescription())),
            'type' => $package->getType(),
            'repository' => $package->getRepository(),
            'language' => $package->getLanguage(),
            # log10 of downloads over the last 7days
            'trendiness' => $trendiness > 0 ? log($trendiness, 10) : 0,
            # log10 of downloads + gh stars
            'popularity' => $popularity,
            'meta' => [
                'downloads' => $downloads['total'],
                'downloads_formatted' => number_format($downloads['total'], 0, ',', ' '),
                'favers' => $faversCount,
                'favers_formatted' => number_format($faversCount, 0, ',', ' '),
            ],
        ];

        if ($package->isAbandoned()) {
            $record['abandoned'] = 1;
            $record['replacementPackage'] = $package->getReplacementPackage() ?: '';
        } else {
            $record['abandoned'] = 0;
            $record['replacementPackage'] = '';
        }

        $record['tags'] = $tags;

        return $record;
    }

    private function createSearchableProvider(Query $update, string $provided)
    {
        $document = $update->createDocument();
        $document->setField('id', $provided);
        $document->setField('objectID', 'virtual:'.$provided);
        $document->setField('name', $provided);
        //$document->setField('package_organisation',  preg_replace('{/.*$}', '', $provided));
        $document->setField('package_name', preg_replace('{^[^/]*/}', '', $provided));
        $document->setField('description', '');
        $document->setField('type', 'virtual-package');
        $document->setField('trendiness', 100);
        $document->setField('repository', '');
        $document->setField('abandoned', 0);
        $document->setField('replacementPackage', '');
        $update->addDocument($document);

    }

    private function getProviders(Package $package): array
    {
        return $this->getEM()->getConnection()->fetchAllAssociative(
            'SELECT lp.packageName
                FROM package p
                JOIN package_version pv ON p.id = pv.package_id
                JOIN link_provide lp ON lp.version_id = pv.id
                WHERE p.id = :id
                AND pv.development = true
                GROUP BY lp.packageName',
            ['id' => $package->getId()]
        );
    }

    private function getTags(Package $package): array
    {
        $tags = $this->getEM()->getConnection()->fetchAllAssociative(
            'SELECT t.name FROM package p
                            JOIN package_version pv ON p.id = pv.package_id
                            JOIN version_tag vt ON vt.version_id = pv.id
                            JOIN tag t ON t.id = vt.tag_id
                            WHERE p.id = :id
                            GROUP BY t.id, t.name',
            ['id' => $package->getId()]
        );

        foreach ($tags as $idx => $tag) {
            $tags[$idx] = $tag['name'];
        }

        return array_values(array_unique(array_map(function ($tag) {
            return preg_replace('{[\s-]+}u', ' ', mb_strtolower(preg_replace('{[\x00-\x1f]+}u', '', $tag), 'UTF-8'));
        }, $tags)));
    }

    private function updateIndexedAt(array $idsToUpdate, string $time)
    {
        $retries = 5;
        // retry loop in case of a lock timeout
        while ($retries--) {
            try {
                $this->getEM()->getConnection()->executeQuery(
                    'UPDATE package SET indexedAt=:indexed WHERE id IN (:ids)',
                    [
                        'ids' => $idsToUpdate,
                        'indexed' => $time,
                    ],
                    ['ids' => Connection::PARAM_INT_ARRAY]
                );
            } catch (\Exception $e) {
                if (!$retries) {
                    throw $e;
                }
                sleep(2);
            }
        }
    }

    private function updateDocumentFromPackage(
        DocumentInterface $document,
        Package $package,
        array $tags
    ) {
        $faversCount = $this->favoriteManager->getFaverCount($package);
        $downloads = $this->downloadManager->getDownloads($package);

        $downloadsLog = $downloads['monthly'] > 0 ? log($downloads['monthly'], 10) : 0;
        $starsLog = $package->getGitHubStars() > 0 ? log($package->getGitHubStars(), 10) : 0;
        $popularity = round($downloadsLog + $starsLog);
        $trendiness = $this->redis->zscore('downloads:trending', $package->getId());


        /** @var \Solarium\QueryType\Update\Query\Document $document  */
        $document->setField('id', $package->getId());
        //$document->setField('objectID', $package->getName());
        $document->setField('name', $package->getName());
        //$document->setField('package_organisation', $package->getVendor());
        $document->setField('package_name', $package->getPackageName());

        $document->setField('description', preg_replace('{[\x00-\x1f]+}u', '', $package->getDescription()));
        $document->setField('type', $package->getType());
        $document->setField('repository', $package->getRepository());
        $document->setField('language', $package->getLanguage());

        # log10 of downloads over the last 7days
        $document->setField('trendiness',  $trendiness > 0 ? log($trendiness, 10) : 0);
        # log10 of downloads + gh stars
        $document->setField('popularity', $popularity);
        $document->setField('downloads', $downloads['total']);
        $document->setField('favers', $faversCount);
        //$document->setField('meta', [
        //    'downloads' => $downloads['total'],
        //    'downloads_formatted' => number_format($downloads['total'], 0, ',', ' '),
        //    'favers' => $faversCount,
        //    'favers_formatted' => number_format($faversCount, 0, ',', ' '),
        //]);

        if ($package->isAbandoned()) {
            $document->setField('abandoned', 1);
            $document->setField('replacementPackage', $package->getReplacementPackage() ?: '');
        } else {
            $document->setField('abandoned', 0);
            $document->setField('replacementPackage', '');
        }

        $tags = array_map(function ($tag) {
            return mb_strtolower(preg_replace('{[\x00-\x1f]+}u', '', $tag), 'UTF-8');
        }, $tags);
        $document->setField('tags', $tags);
    }
}
