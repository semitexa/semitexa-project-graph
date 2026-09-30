<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Orders;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;

#[AsPayloadHandler(payload: PlaceOrderPayload::class, resource: OrderResource::class)]
final class PlaceOrderHandler
{
    #[InjectAsReadonly]
    protected OrderRepository $orders;

    public function handle(PlaceOrderPayload $payload): OrderResource
    {
        $this->orders->save(new OrderPlaced());

        return new OrderResource();
    }
}
