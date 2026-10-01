<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Orders;

final class SqlOrderRepository implements OrderRepository
{
    public function save(OrderPlaced $event): void
    {
    }
}
