<?php

class SearchMatcher
{
    private PDO $db;


    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function search(array $words, int $limit = 50): array
    {
        if (empty($words)) {
            return [];
        }


        $conditions = [];
        $parameters = [];

        foreach ($words as $index => $word) {

            $parameter = ':word' . $index;

            $conditions[] = "
                (
                    p.title LIKE $parameter
                    OR p.description LIKE $parameter
                    OR p.sku LIKE $parameter
                    OR c.name LIKE $parameter
                    OR c.slug LIKE $parameter
                    OR b.name LIKE $parameter
                    OR b.slug LIKE $parameter
                )
            ";


            $parameters[$parameter] = '%' . $word . '%';
        }

        $whereClause = implode(' AND ', $conditions);


      
        $limit = max(1, min($limit, 100));


        $sql = "
            SELECT

                p.id,
                p.sku,
                p.title,
                p.slug,
                p.description,
                p.image,
                p.price,
                p.stock,
                p.status,
                p.category_id,
                p.brand_id,
                p.attributes,

                c.name AS category_name,
                c.slug AS category_slug,

                b.name AS brand_name,
                b.slug AS brand_slug

            FROM products p

            LEFT JOIN categories c
                ON p.category_id = c.id

            LEFT JOIN brands b
                ON p.brand_id = b.id

            WHERE p.status = 1

            AND $whereClause

            ORDER BY p.id DESC

            LIMIT $limit
        ";


        $stmt = $this->db->prepare($sql);


       
        foreach ($parameters as $parameter => $value) {

            $stmt->bindValue(
                $parameter,
                $value,
                PDO::PARAM_STR
            );
        }


        $stmt->execute();


        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}