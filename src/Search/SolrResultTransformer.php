<?php declare(strict_types=1);

namespace App\Search;

use Pagerfanta\Pagerfanta;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @phpstan-type SearchResult array{
 *     total: int,
 *     next?: string,
 *     results: array<array{
 *         name: string,
 *         description: string,
 *         url: string,
 *         repository: string,
 *         downloads?: int,
 *         favers?: int,
 *         virtual?: bool,
 *         abandoned?: true|string
 *     }>
 * }
 */
final class SolrResultTransformer
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * @param array{
     *     nbHits: int,
     *     page: int,
     *     nbPages: int,
     *     hits: array<array{
     *         id: int,
     *         name: string,
     *         description: string,
     *         repository: string,
     *         meta: array{downloads: int, favers: int},
     *         abandoned?: bool,
     *         replacementPackage?: string
     *     }>
     * } $results
     *
     * @phpstan-return SearchResult
     */
    public function transform(Query $query, Pagerfanta $paginator): array
    {
        $result = [
            'hits' => [],
            'facets' => [],
            'page' => $query->page,
            'index' => '', // todo
            'query' => $query->query,
            'params' => '',
            'hitsPerPage' => $paginator->getMaxPerPage(),
            'exhaustiveFacetsCount' => true,
            'exhaustiveNbHits' => true,
            'processingTimeMS' => 1,
            'nbPages' => $paginator->getNbPages(),
            'nbHits' => $paginator->getNbResults(),
        ];

        $metadata = $this->fetchMetadata($paginator);
        $result['facets'] = $metadata['facets'];

        foreach ($paginator as $package) {
            if (ctype_digit((string)$package->id)) {
                $id = +$package->id;
                $url = $this->urlGenerator->generate('view_package', ['name' => $package->name], UrlGeneratorInterface::ABSOLUTE_URL);
            } else {
                $id = $package->id;
                $url = $this->urlGenerator->generate('view_providers', ['name' => $package->name], UrlGeneratorInterface::ABSOLUTE_URL);
            }
            $row = [
                'id'=>$id,
                'name' => $package->name,
                'description' => $package->description ?: '',
                'url' => $url,
                'repository' => $package->repository,

                'objectID' => $package->name,
                'package_name' => $package->package_name ?? $package->name,
                'type' => $package->type,
                'tags' => $package->tags,
                'language' => $package->language ?? 'php',
                'abandoned' => $package->abandoned,
                'popularity' => $package->popularity,
                'replacementPackage' => $package->replacementPackage,
            ];

            if (!empty($package->abandoned)) {
                $row['abandoned'] = isset($package->replacementPackage) && $package->replacementPackage !== '' ? $package->replacementPackage : true;
            }

            if (ctype_digit((string)$package->id)) {
                $row['downloads'] = $metadata['downloads'][$package->id] ?? 0;
                $row['favers'] = $metadata['favers'][$package->id]??0;
                $row['trendiness'] = $package->trendiness;
                $row['meta'] = [
                    'downloads' => $row['downloads'],
                    'downloads_formatted' => number_format($row['downloads'], 0, '.', ' '),
                    'favers' => $row['favers'],
                    'favers_formatted' => number_format($row['favers'], 0, '.', ' ')
                ];

                $row['_highlightResult'] = [
                    'description' => [
                        'fullyHighlighted' => false,
                        'value' => $package->description ?: '',
                        'matchLevel' => "full"
                    ],
                    'name' => [
                        'fullyHighlighted' => false,
                        'value' => $package->name ?: '',
                        'matchLevel' => "full"
                    ]
                ];


            } else {
                $row['virtual'] = true;
            }


            $result['hits'][] = $row;
        }

        if ($paginator->hasNextPage()) {
            $params = array(
                '_format' => 'json',
                'q' => $query->query,
                'page' => $paginator->getNextPage()
            );
            if ($query->tags) {
                $params['tags'] = $query->tags;
            }
            if ($query->type) {
                $params['type'] = $query->type;
            }
            if ($query->perPage !== 15) {
                $params['per_page'] = $query->perPage;
            }
            $result['next'] = $this->urlGenerator->generate('search_solr_api_q', $params, UrlGeneratorInterface::ABSOLUTE_URL);
        }


        return $result;
    }

    private function fetchMetadata(Pagerfanta $paginator): array
    {
        $metadata = array();

        $facetsRes = [
            'tags' => [],
            'type' => [],
        ];
        foreach ($paginator as $package) {
            $facetsRes['tags'] = array_merge($facetsRes['tags'], $package->tags ?? []);
            $facetsRes['type'][] = $package->type;
            if (ctype_digit($package->id)) {
                $metadata['downloads'][$package->id] = $package->downloads;
                $metadata['favers'][$package->id] = $package->favers;
            }
        }

        $facetsRes['tags'] = array_count_values($facetsRes['tags']);
        $facetsRes['type'] = array_count_values($facetsRes['type']);
        $metadata['facets'] = $facetsRes;
        return $metadata;
    }
}
