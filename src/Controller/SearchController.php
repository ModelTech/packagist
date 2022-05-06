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

namespace App\Controller;

//use Algolia\AlgoliaSearch\SearchClient;
use App\Entity\Version;
use App\Form\Model\SearchQuery;
use App\Form\Type\SearchQueryType;
use App\Entity\Package;
use App\Entity\PhpStat;
use App\Util\Killswitch;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Solarium\SolariumAdapter;
use Predis\Connection\ConnectionException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Predis\Client as RedisClient;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class SearchController extends Controller
{


    /**
     * Rendered by views/Web/search_section.html.twig
     */
    public function searchFormAction(Request $req)
    {
        $form = $this->createForm(SearchQueryType::class, new SearchQuery(), [
            'action' => $this->generateUrl('search.ajax'),
        ]);

        $filteredOrderBys = $this->getFilteredOrderedBys($req);

        $this->computeSearchQuery($req, $filteredOrderBys);

        $form->handleRequest($req);

        return $this->render('web/search_form.html.twig', [
            'searchQuery' => $req->query->all()['search_query']['query'] ?? '',
        ]);
    }

    private function checkForQueryMatch(Request $req)
    {
        $q = $req->query->get('query');
        if ($q) {
            $package = $this->doctrine->getRepository(Package::class)->findOneBy(['name' => $q]);
            if ($package) {
                return $this->redirectToRoute('view_package', ['name' => $package->getName()]);
            }
        }
    }

    /**
     * @param array $orderBys
     *
     * @return array
     */
    protected function getNormalizedOrderBys(array $orderBys)
    {
        $normalizedOrderBys = array();

        foreach ($orderBys as $sort) {
            $normalizedOrderBys[$sort['sort']] = $sort['order'];
        }

        return $normalizedOrderBys;
    }

    /**
     * @Route("/search2/", name="search2.ajax" )
     * @Route("/search2/1/indexes/{x}/queries", name="search22.ajax" )
     *  Route("/search2.{_format}", requirements={"_format"="(html|json)"}, name="search", defaults={"_format"="html"}, methods={"GET"})
     */
    public function searchAction(Request $req, \Nelmio\SolariumBundle\ClientRegistry $solariumReg)
    {
        $parameters = json_decode($req->getContent(), true);

        $form = $this->createForm(SearchQueryType::class, new SearchQuery());
        $filteredOrderBys = $this->getFilteredOrderedBys($req);
        $solarium = $solariumReg->getClient();
        $results = [];

        foreach ($parameters['requests'] ?? [] as $request) {
            $indexName = $request['indexName'];
            $params = [];
            parse_str($request['params'], $params);
            $tagsFilter = $params['tagFilters'] ?? '';
            $maxValuesPerFacet = $params['maxValuesPerFacet'] ?? 100;
            $page = +($params['page'] ?? 0) + 1;
            $facets = $params['facets'] ?? [];
            $query = $params['query'] ?? '';


            //new \Solarium\Core\Client\Client();

            $normalizedOrderBys = $this->getNormalizedOrderBys($filteredOrderBys);

            $this->computeSearchQuery($req, $filteredOrderBys);

            $rawFacetFilters = json_decode($params['facetFilters'] ?? '');
            $facetFilters = ['tags'=>[],'type'=>[]];
            if(is_array($rawFacetFilters))
            foreach($rawFacetFilters as $filterGroup){
                if(is_array($filterGroup) )
                foreach($filterGroup as $filter){
                    [$key, $value] = explode(':', $filter);
                    $facetFilters[$key][] = $value;
                }
            }



            $typeFilter = $facetFilters['type']??[];
            $tagsFilter = $facetFilters['tags']??[];
            //$typeFilter = str_replace('%type%', '', $req->query->get('type'));
            //$tagsFilter = $req->query->get('tags');

          //  if ($query || $typeFilter || $tagsFilter) {
                /** @ var $solarium \Solarium_Client */
                //   $solarium = $this->get('solarium.client');
                $select = $solarium->createSelect();

                // configure dismax
                $dismax = $select->getDisMax();
                $dismax->setQueryFields(
                    implode(' ',
                        [
                            'name^4',
                            'package_name^4',
                            'description',
                            'tags', 'text',
                            'text_ngram',
                            'name_split^2'
                        ]));
                $dismax->setPhraseFields('description');
                $dismax->setBoostFunctions('log(trendiness)^10');
                $dismax->setMinimumMatch(1);
                $dismax->setQueryParser('edismax');

                // filter by type
                if ($typeFilter) {
                    $types = [];
                    foreach ((array)$typeFilter as $tag) {
                        $types[] = $select->getHelper()->escapeTerm($tag);
                    }
                    foreach($typeFilter as $typeValue){
                        $filterQueryTerm = sprintf('type:("%s")', implode('" AND "', $types));
                        $filterQuery = $select->createFilterQuery('type')->setQuery($filterQueryTerm);
                        $select->addFilterQuery($filterQuery);
                    }
                }

                // filter by tags
                if ($tagsFilter) {
                    $tags = array();
                    foreach ((array)$tagsFilter as $tag) {
                        $tags[] = $select->getHelper()->escapeTerm($tag);
                    }
                    $filterQueryTerm = sprintf('tags:("%s")', implode('" AND "', $tags));
                    $filterQuery = $select->createFilterQuery('tags')->setQuery($filterQueryTerm);
                    $select->addFilterQuery($filterQuery);
                }

                if (!empty($filteredOrderBys)) {
                    $select->addSorts($normalizedOrderBys);
                }

                //$form->handleRequest($req);
                //if ($form->isValid()) {
                $escapedQuery = $select->getHelper()->escapeTerm($query);
                //dd($escapedQuery);
                //$escapedQuery = $select->getHelper()->escapeTerm($form->getData()->getQuery());
                $escapedQuery = preg_replace('/(^| )\\\\-(\S)/', '$1-$2', $escapedQuery);
                $escapedQuery = preg_replace('/(^| )\\\\\+(\S)/', '$1+$2', $escapedQuery);
                if ((substr_count($escapedQuery, '"') % 2) == 0) {
                    $escapedQuery = str_replace('\\"', '"', $escapedQuery);
                }
                $select->setQuery('"'.$escapedQuery.'"');
                $paginator = new Pagerfanta(new SolariumAdapter($solarium, $select));

                $paginator->setMaxPerPage($maxValuesPerFacet);
                $paginator->setCurrentPage($page, false, true);

                $metadata = array();

                $facetsRes = [
                    'tags' => [],
                    'type' => [],
                ];
                foreach ($paginator as $package) {
                  //  dump($package);
                    $facetsRes['tags'] = array_merge($facetsRes['tags'], $package->tags ?? []);
                    $facetsRes['type'][] = $package->type;
                    if (is_numeric($package->id)) {
                        $metadata['downloads'][$package->id] = $package->downloads;
                        $metadata['favers'][$package->id] = $package->favers;
                    }
                }
                //die;



                $facetsRes['tags'] = array_count_values($facetsRes['tags']);
                $facetsRes['type'] = array_count_values($facetsRes['type']);
                try {
                    $result = array(
                        'hits' => array(),
                        'facets' => $facetsRes,
                        'page' => $page,
                        'index' => $indexName,
                        'query' => $query,
                        'params' => $request['params'],
                        'hitsPerPage' => $paginator->getMaxPerPage(),
                        'exhaustiveFacetsCount' => true,
                        'exhaustiveNbHits' => true,
                        'processingTimeMS' => 5,
                        'nbPages' => $paginator->getNbPages(),
                        'nbHits' => $paginator->getNbResults(),
                    );
                } catch (\Throwable $e) {
                    return JsonResponse::create(array(
                        'status' => 'error',
                        'message' => 'Could not connect to the search server',
                    ), 500)->setCallback($req->query->get('callback'));
                }

                foreach ($paginator as $package) {
                    //dd($package);
                    if (ctype_digit((string)$package->id)) {
                        $url = $this->generateUrl('view_package', array('name' => $package->name), UrlGeneratorInterface::ABSOLUTE_URL);
                    } else {
                        $url = $this->generateUrl('view_providers', array('name' => $package->name), UrlGeneratorInterface::ABSOLUTE_URL);
                    }

                    $row = array(
                        'objectID' => $package->name,
                        'package_name' => $package->package_name ?? $package->name,
                        'name' => $package->name,
                        'description' => $package->description ?: '',
                        'url' => $url,
                        'repository' => $package->repository,
                        'type' => $package->type,
                        'tags' => $package->tags,
                        'language' => $package->language ?? 'php',
                        'abandoned' => $package->abandoned,
                        'popularity' => $package->popularity,
                        'replacementPackage' => $package->replacementPackage,
                    );
                    if (is_numeric($package->id)) {
                        $row['downloads'] = $metadata['downloads'][$package->id];
                        $row['favers'] = $metadata['favers'][$package->id];
                        $row['trendiness'] = $package->trendiness;
                        $row['meta'] = [
                            'downloads' => $metadata['downloads'][$package->id],
                            'downloads_formatted' => number_format($metadata['downloads'][$package->id], 0, '.', ' '),
                            'favers' => $metadata['favers'][$package->id],
                            'favers_formatted' => number_format($metadata['favers'][$package->id], 0, '.', ' ')
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
                $results[] = $result;

                if ($paginator->hasNextPage()) {
                    $params = array(
                        '_format' => 'json',
                        'q' => $form->getData()->getQuery(),
                        'page' => $paginator->getNextPage()
                    );
                    if ($tagsFilter) {
                        $params['tags'] = (array)$tagsFilter;
                    }
                    if ($typeFilter) {
                        $params['type'] = $typeFilter;
                    }
                    if ($maxValuesPerFacet !== 15) {
                        $params['maxValuesPerFacet'] = $maxValuesPerFacet;
                    }
                    $result['next'] = $this->generateUrl('search2', $params, UrlGeneratorInterface::ABSOLUTE_URL);
                }


            //} elseif ($req->getRequestFormat() === 'json') {
            //    return JsonResponse::create(array(
            //        'error' => 'Missing search query, example: ?q=example'
            //    ), 400)->setCallback($req->query->get('callback'));
            //}

        }
        return (new JsonResponse(['results' => $results]))
            ->setCallback($req->query->get('callback'));
        //return JsonResponse::create(['results'=>$results])->setCallback($req->query->get('callback'));

        return $this->render('web/search.html.twig');
    }

    private function searchAction2(Request $req, \Nelmio\SolariumBundle\ClientRegistry $solariumReg)
    {
        $parameters = json_decode($req->getContent(), true);

        $form = $this->createForm(SearchQueryType::class, new SearchQuery());
        $filteredOrderBys = $this->getFilteredOrderedBys($req);
        $solarium = $solariumReg->getClient();
        foreach ($parameters['requests'] ?? [] as $request) {
            $indexName = $request['indexName'];
            $params = [];
            parse_str($request['params'], $params);
            $tagFilters = $params['tagFilters'] ?? [];

        }
        //new \Solarium\Core\Client\Client();
        die;
        $normalizedOrderBys = $this->getNormalizedOrderBys($filteredOrderBys);

        $this->computeSearchQuery($req, $filteredOrderBys);

        $typeFilter = str_replace('%type%', '', $req->query->get('type'));
        $tagsFilter = $req->query->get('tags');

        if ($req->query->has('search_query') || $typeFilter || $tagsFilter) {
            /** @var $solarium \Solarium_Client */
            $solarium = $this->get('solarium.client');
            $select = $solarium->createSelect();

            // configure dismax
            $dismax = $select->getDisMax();
            $dismax->setQueryFields(array('name^4', 'package_name^4', 'description', 'tags', 'text', 'text_ngram', 'name_split^2'));
            $dismax->setPhraseFields(array('description'));
            $dismax->setBoostFunctions(array('log(trendiness)^10'));
            $dismax->setMinimumMatch(1);
            $dismax->setQueryParser('edismax');

            // filter by type
            if ($typeFilter) {
                $filterQueryTerm = sprintf('type:"%s"', $select->getHelper()->escapeTerm($typeFilter));
                $filterQuery = $select->createFilterQuery('type')->setQuery($filterQueryTerm);
                $select->addFilterQuery($filterQuery);
            }

            // filter by tags
            if ($tagsFilter) {
                $tags = array();
                foreach ((array)$tagsFilter as $tag) {
                    $tags[] = $select->getHelper()->escapeTerm($tag);
                }
                $filterQueryTerm = sprintf('tags:("%s")', implode('" AND "', $tags));
                $filterQuery = $select->createFilterQuery('tags')->setQuery($filterQueryTerm);
                $select->addFilterQuery($filterQuery);
            }

            if (!empty($filteredOrderBys)) {
                $select->addSorts($normalizedOrderBys);
            }

            $form->handleRequest($req);
            if ($form->isValid()) {
                $escapedQuery = $select->getHelper()->escapeTerm($form->getData()->getQuery());
                $escapedQuery = preg_replace('/(^| )\\\\-(\S)/', '$1-$2', $escapedQuery);
                $escapedQuery = preg_replace('/(^| )\\\\\+(\S)/', '$1+$2', $escapedQuery);
                if ((substr_count($escapedQuery, '"') % 2) == 0) {
                    $escapedQuery = str_replace('\\"', '"', $escapedQuery);
                }
                $select->setQuery($escapedQuery);
            }

            $paginator = new Pagerfanta(new SolariumAdapter($solarium, $select));

            $perPage = $req->query->getInt('per_page', 15);
            if ($perPage <= 0 || $perPage > 100) {
                if ($req->getRequestFormat() === 'json') {
                    return JsonResponse::create(array(
                        'status' => 'error',
                        'message' => 'The optional packages per_page parameter must be an integer between 1 and 100 (default: 15)',
                    ), 400)->setCallback($req->query->get('callback'));
                }

                $perPage = max(0, min(100, $perPage));
            }
            $paginator->setMaxPerPage($perPage);

            $paginator->setCurrentPage($req->query->get('page', 1), false, true);

            $metadata = array();

            foreach ($paginator as $package) {
                if (is_numeric($package->id)) {
                    $metadata['downloads'][$package->id] = $package->downloads;
                    $metadata['favers'][$package->id] = $package->favers;
                }
            }

            if ($req->getRequestFormat() === 'json') {
                try {
                    $result = array(
                        'results' => array(),
                        'total' => $paginator->getNbResults(),
                    );
                } catch (\Solarium_Client_HttpException $e) {
                    return JsonResponse::create(array(
                        'status' => 'error',
                        'message' => 'Could not connect to the search server',
                    ), 500)->setCallback($req->query->get('callback'));
                }

                foreach ($paginator as $package) {
                    if (ctype_digit((string)$package->id)) {
                        $url = $this->generateUrl('view_package', array('name' => $package->name), UrlGeneratorInterface::ABSOLUTE_URL);
                    } else {
                        $url = $this->generateUrl('view_providers', array('name' => $package->name), UrlGeneratorInterface::ABSOLUTE_URL);
                    }

                    $row = array(
                        'name' => $package->name,
                        'description' => $package->description ?: '',
                        'url' => $url,
                        'repository' => $package->repository,
                    );
                    if (is_numeric($package->id)) {
                        $row['downloads'] = $metadata['downloads'][$package->id];
                        $row['favers'] = $metadata['favers'][$package->id];
                    } else {
                        $row['virtual'] = true;
                    }
                    $result['results'][] = $row;
                }

                if ($paginator->hasNextPage()) {
                    $params = array(
                        '_format' => 'json',
                        'q' => $form->getData()->getQuery(),
                        'page' => $paginator->getNextPage()
                    );
                    if ($tagsFilter) {
                        $params['tags'] = (array)$tagsFilter;
                    }
                    if ($typeFilter) {
                        $params['type'] = $typeFilter;
                    }
                    if ($perPage !== 15) {
                        $params['per_page'] = $perPage;
                    }
                    $result['next'] = $this->generateUrl('search', $params, UrlGeneratorInterface::ABSOLUTE_URL);
                }

                return JsonResponse::create($result)->setCallback($req->query->get('callback'));
            }

            if ($req->isXmlHttpRequest()) {
                try {
                    return $this->render('web/search.html.twig', array(
                        'packages' => $paginator,
                        'meta' => $metadata,
                        'noLayout' => true,
                    ));
                } catch (\Twig_Error_Runtime $e) {
                    if (!$e->getPrevious() instanceof \Solarium_Client_HttpException) {
                        throw $e;
                    }
                    return JsonResponse::create(array(
                        'status' => 'error',
                        'message' => 'Could not connect to the search server',
                    ), 500)->setCallback($req->query->get('callback'));
                }
            }

            return $this->render('web/search.html.twig', array(
                'packages' => $paginator,
                'meta' => $metadata,
            ));
        } elseif ($req->getRequestFormat() === 'json') {
            return JsonResponse::create(array(
                'error' => 'Missing search query, example: ?q=example'
            ), 400)->setCallback($req->query->get('callback'));
        }

        return $this->render('web/search.html.twig');
    }


    /**
     * @param Request $req
     *
     * @return array
     */
    protected function getFilteredOrderedBys(Request $req)
    {
        $orderBys = $req->query->all()['orderBys'] ?? [];
        if (!$orderBys) {
            $orderBys = $req->query->all()['search_query']['orderBys'] ?? [];
        }

        if ($orderBys) {
            $allowedSorts = [
                'downloads' => 1,
                'favers' => 1
            ];

            $allowedOrders = [
                'asc' => 1,
                'desc' => 1,
            ];

            $filteredOrderBys = [];

            foreach ((array)$orderBys as $orderBy) {
                if (isset($orderBy['sort'])
                    && isset($allowedSorts[$orderBy['sort']])
                    && isset($orderBy['order'])
                    && isset($allowedOrders[$orderBy['order']])) {
                    $filteredOrderBys[] = $orderBy;
                }
            }
        } else {
            $filteredOrderBys = [];
        }

        return $filteredOrderBys;
    }

    /**
     * @param Request $req
     * @param array $filteredOrderBys
     */
    private function computeSearchQuery(Request $req, array $filteredOrderBys)
    {
        // transform q=search shortcut
        if ($req->query->has('q') || $req->query->has('orderBys')) {
            $searchQuery = [];

            $q = $req->query->get('q');

            if ($q !== null) {
                $searchQuery['query'] = $q;
            }

            if (!empty($filteredOrderBys)) {
                $searchQuery['orderBys'] = $filteredOrderBys;
            }

            $req->query->set(
                'search_query',
                $searchQuery
            );
        }
    }
}
