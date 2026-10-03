<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Traits;

use IgniterLabs\SquarePOS\Models\SquareLink;
use IgniterLabs\SquarePOS\Models\SquareOrder;
use IgniterLabs\SquarePOS\Models\SquareStock;

trait ManagesSquareAttributes
{
    protected function bootSquareLink(): void
    {
        $this->bootSquareRelation('squareLink', 'morphOne', [
            SquareLink::class, 'name' => 'linkable', 'delete' => true,
        ]);
    }

    protected function bootSquareStock(): void
    {
        $this->bootSquareRelation('squareStock', 'hasOne', [
            SquareStock::class, 'foreignKey' => 'stock_id', 'otherKey' => 'id', 'delete' => true,
        ]);
    }

    protected function bootSquareOrder(): void
    {
        $this->bootSquareRelation('squareOrder', 'hasOne', [
            SquareOrder::class, 'foreignKey' => 'order_id', 'otherKey' => 'order_id', 'delete' => true,
        ]);
    }

    protected function bootSquareRelation(string $name, string $type, array $config): void
    {
        $relations = $this->model->relation;
        $relations[$type][$name] = $config;
        $this->model->relation = $relations;
    }
}
