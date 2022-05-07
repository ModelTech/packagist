<?php

namespace App\Controller;

use App\Search\Algolia;
use App\Search\Query;
use App\Search\ResultTransformer;
use App\Search\Solr;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

/**
 * Emulate Algolia search via Solarium
 * @author Alexander Rakushin
 */
class SearchController extends Controller
{

    /**
     * Override search Api (search_api) entrypoint.
     * Implements Solr search driver
     */
    #[Route('/search.json', name: 'search_api_via_solr', methods: 'GET', defaults: ['_format' => 'json'])]
    public function searchSolrApiJSON(
        Request                               $req,
        Solr $solarium,
        ResultTransformer $resultTransformer
    ): JsonResponse
    {
        $query = new Query(
            $req->query->has('q') ? $req->query->get('q') : $req->query->get('query', ''),
            (array) ($req->query->all()['tags'] ?? []),
            $req->query->get('type', ''),
            $req->query->getInt('per_page', 15),
            $req->query->getInt('page', 1)
        );
        $result = $solarium->search($query);

        // transform algolia format in to packagist json
        $result = $resultTransformer->transform($query, $result);

        $response = (new JsonResponse($result))->setCallback($req->query->get('callback'));
        $response->setSharedMaxAge(300);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    /**
     * Emulate Algolia search entrypoint via Solr search driver
     */
    #[Route('/search2/1/indexes/{x}/queries', name: 'search_api_emulate_algolia', methods: 'POST', defaults: ['_format' => 'json'])]
    public function searchSolrApi(
        Request                               $req,
        Algolia                               $algolia,
        Solr $solarium
    ): JsonResponse
    {
        // emulate Algolia entrypoint
        $results = [];
        $parameters = json_decode($req->getContent(), true);
        foreach ($parameters['requests'] ?? [] as $request) {
            $indexName = $request['indexName'];
            $params = [];
            parse_str($request['params'], $params);
            $page = (int)($params['page'] ?? 0) + 1;
            $query = $params['query'] ?? '';

            $facetFilters = $this->getFacetFilters($params['facetFilters'] ?? '');


            $typeFilter = $facetFilters['type'] ?? [];
            $tagsFilter = $facetFilters['tags'] ?? [];
            $query = new Query(
                $query,
                $tagsFilter,
                implode(',', $typeFilter),
                ($params['maxValuesPerFacet'] ?? 15),
                $page
            );

            $result = $solarium->search($query);
            $result['index'] = $indexName;
            $result['params'] = $request['params'];
            $results[] = $result;
        }

        $response = (new JsonResponse(['results'=>$results]))->setCallback($req->query->get('callback'));
        $response->setSharedMaxAge(300);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }


    private function getFacetFilters(string|null $decodedFacetFilters): array
    {
        $rawFacetFilters = json_decode($decodedFacetFilters ?? '');
        $facetFilters = ['tags' => [], 'type' => []];
        if (is_array($rawFacetFilters))
            foreach ($rawFacetFilters as $filterGroup) {
                if (is_array($filterGroup))
                    foreach ($filterGroup as $filter) {
                        [$key, $value] = explode(':', $filter);
                        $facetFilters[$key][] = $value;
                    }
            }
        return $facetFilters;
    }
}
