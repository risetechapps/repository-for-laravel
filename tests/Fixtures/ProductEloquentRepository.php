<?php

namespace RiseTechApps\Repository\Tests\Fixtures;

use RiseTechApps\Repository\Core\BaseRepository;

class ProductEloquentRepository extends BaseRepository implements ProductRepository
{
    public function entity(): string
    {
        return Product::class;
    }

    public function entityOn(): Product
    {
        return new Product();
    }
}
