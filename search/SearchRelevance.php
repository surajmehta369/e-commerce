<?php

class RelevanceScorer
{
    /**
     * Score and sort matching products.
     *
     * @param array $products Products returned by SearchMatcher.
     * @param array $words Search words from SearchQuery.
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

        // Highest score first.
        usort($products, function ($a, $b) {
            return $b['relevance_score'] <=> $a['relevance_score'];
        });

        return $products;
    }


    /**
     * Calculate relevance score for one product.
     */
    private function calculateScore(array $product, array $words): int
    {
        $score = 0;

        foreach ($words as $word) {
            $word = strtolower(trim($word));

            if ($word === '') {
                continue;
            }

            /*
             * Product title
             */

            $title = strtolower($product['title'] ?? '');

            if ($title === $word) {
                $score += 100;
            } elseif (str_contains($title, $word)) {
                $score += 80;
            }


            /*
             * Brand
             */

            $brand = strtolower($product['brand_name'] ?? '');

            if ($brand === $word) {
                $score += 70;
            } elseif (str_contains($brand, $word)) {
                $score += 60;
            }


            /*
             * SKU
             */

            $sku = strtolower($product['sku'] ?? '');

            if ($sku === $word) {
                $score += 60;
            } elseif (str_contains($sku, $word)) {
                $score += 50;
            }


            /*
             * Category
             */

            $category = strtolower($product['category_name'] ?? '');

            if ($category === $word) {
                $score += 40;
            } elseif (str_contains($category, $word)) {
                $score += 30;
            }


            /*
             * Description
             */

            $description = strtolower($product['description'] ?? '');

            if (str_contains($description, $word)) {
                $score += 10;
            }
        }

        return $score;
    }
}
