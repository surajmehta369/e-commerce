<?php

class RelevanceScorer
{
    /**
     * 
     *
     * @param array $products 
     * @param array $words 
     * @return array
     */
    public function score(array $products, array $words): array
    {
        if (empty($products) || empty($words)) {
            return [];
        }

        foreach ($products as &$product) {
            $product['relevance_score'] = $this->calculateScore(
                $product,
                $words
            );
        }

        unset($product);

        usort($products, function ($a, $b) {
            return $b['relevance_score'] <=> $a['relevance_score'];
        });

        return $products;
    }

    private function calculateScore(array $product, array $words): int
    {
        $score = 0;

        foreach ($words as $word) {
            $word = strtolower(trim($word));

            if ($word === '') {
                continue;
            }

    
            $title = strtolower($product['title'] ?? '');

            if ($title === $word) {
                $score += 100;
            } elseif (str_contains($title, $word)) {
                $score += 80;
            }

            $brand = strtolower($product['brand_name'] ?? '');

            if ($brand === $word) {
                $score += 70;
            } elseif (str_contains($brand, $word)) {
                $score += 60;
            }

            $sku = strtolower($product['sku'] ?? '');

            if ($sku === $word) {
                $score += 60;
            } elseif (str_contains($sku, $word)) {
                $score += 50;
            }

            $category = strtolower($product['category_name'] ?? '');

            if ($category === $word) {
                $score += 40;
            } elseif (str_contains($category, $word)) {
                $score += 30;
            }



            $description = strtolower($product['description'] ?? '');

            if (str_contains($description, $word)) {
                $score += 10;
            }
        }

        return $score;
    }
}
