<?php

class SearchEngine
{
    private SearchMatcher $matcher;
    private RelevanceScorer $scorer;


    public function __construct(
        SearchMatcher $matcher,
        RelevanceScorer $scorer
    ) {
        $this->matcher = $matcher;
        $this->scorer = $scorer;
    }


    /**
     * 
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function search(string $query, int $limit = 50): array
    {
    
        $searchQuery = new SearchQuery($query);

        $words = $searchQuery->getWords();

        if (empty($words)) {
            return [];
        }
        $products = $this->matcher->search(
            $words,
            $limit
        );
        $products = $this->scorer->score(
            $products,
            $words
        );

        return $products;
    }
}
