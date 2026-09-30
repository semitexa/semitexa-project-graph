<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Mail;

use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Orders\OrderPlaced;

#[AsEventListener(event: OrderPlaced::class, execution: 'sync')]
final class SendReceiptListener
{
    public function handle(OrderPlaced $event): void
    {
    }
}
