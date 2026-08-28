<?php

class SearchQuery
{
    private string $query;


    private array $words;


    public function __construct(string $query)
    {
        $this->query = trim($query);

        $this->words = $this->parse();
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getWords(): array
    {
        return $this->words;
    }

    private function parse(): array
    {
        if ($this->query === '') {
            return [];
        }


        $query = preg_replace('/\s+/', ' ', $this->query);

        $query = strtolower($query);


        $query = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $query);

        $words = preg_split('/\s+/', trim($query));

        $words = array_filter($words, function ($word) {
            return $word !== '';
        });


        return array_values($words);
    }
}