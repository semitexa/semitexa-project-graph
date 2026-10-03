<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Support;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A refusal a caller can parse in the format it asked for. With --json the
 * commands answered success as JSON and failure as SymfonyStyle text on the
 * same stdout, so `| jq` broke exactly when something went wrong (round 2).
 */
trait RefusesInMachineFormat
{
    private function refuse(OutputInterface $output, SymfonyStyle $io, string $message, bool $machine): int
    {
        if ($machine) {
            $output->writeln((string) json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);
        } else {
            $io->error($message);
        }

        return Command::FAILURE;
    }
}
