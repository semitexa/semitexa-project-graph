<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Orders;

use Semitexa\Core\Attribute\AsPublicPayload;

#[AsPublicPayload(path: '/orders', methods: ['POST'], name: 'fixture.orders.place')]
final class PlaceOrderPayload
{
}
