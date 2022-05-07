<?php declare(strict_types=1);

namespace App\Search;

use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use Algolia\AlgoliaSearch\SearchClient;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Solarium\SolariumAdapter;
use Solarium\Client;
use Solarium\QueryType\Select\Query\Query as SelectQuery;
/**
 * @phpstan-import-type SearchResult from ResultTransformer
 */
final class Solr
{
    public function __construct(
        private Client $searchClient,
       // private string $indexName,
        private SolrResultTransformer $transformer,
    ) {
    }

    /**
     * @phpstan-return SearchResult
     */
    public function search(Query $query): array
    {
        $select = $this->searchClient->createSelect();
        $this->configureDismax($select);

        $this->filterByType($query->type, $select);
        $this->filterByTags($query->tags, $select);
        $this->filterByQuery($query->query, $select);
        $paginator = new Pagerfanta(new SolariumAdapter($this->searchClient, $select));

        $paginator->setMaxPerPage($query->perPage);
        $paginator->setCurrentPage($query->page+1);

        return $this->transformer->transform(
            $query,
            $paginator
        );
    }


    private function configureDismax(SelectQuery $select): void
    {
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
        $dismax->setMinimumMatch('1');
        $dismax->setQueryParser('edismax');
    }

    private function filterByType(string $type, SelectQuery $select): void
    {
        $typeFilter = explode(',', $type);
        $types = [];
        foreach ($typeFilter as $tag) {
            if(!$tag){
                continue;
            }
            $types[] = $select->getHelper()->escapeTerm($tag);
        }
        if(!count($types)){
            return;
        }
       // foreach ($types as $typeValue) {
            $filterQueryTerm = sprintf('type:("%s")', implode('" AND "', $types));

          $filterQuery = $select->createFilterQuery('type')->setQuery($filterQueryTerm);
            $select->addFilterQuery($filterQuery);
        //}
    }

    private function filterByTags(array $tagsFilter, SelectQuery $select): void
    {
        if(!count($tagsFilter)){
            return;
        }
        $tags = array();
        foreach ($tagsFilter as $tag) {
            $tags[] = $select->getHelper()->escapeTerm($tag);
        }
        $filterQueryTerm = sprintf('tags:("%s")', implode('" OR "', $tags));
         $filterQuery = $select->createFilterQuery('tags')->setQuery($filterQueryTerm);
        $select->addFilterQuery($filterQuery);
    }

    private function filterByQuery(string $query, SelectQuery $select): void
    {
        $escapedQuery = $select->getHelper()->escapeTerm($query);
        $escapedQuery = preg_replace('/(^| )\\\\-(\S)/', '$1-$2', $escapedQuery);
        $escapedQuery = preg_replace('/(^| )\\\\\+(\S)/', '$1+$2', $escapedQuery);
        if ((substr_count($escapedQuery, '"') % 2) == 0) {
            $escapedQuery = str_replace('\\"', '"', $escapedQuery);
        }
        $select->setQuery('"' . $escapedQuery . '"');
    }


}
