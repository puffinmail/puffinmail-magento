<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Psr\Log\LoggerInterface;

/**
 * Logs to var/log/puffinmail.log (see di.xml). Debug lines only when Advanced > Debug Log is on.
 */
class Logger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->config->isDebug()) {
            $this->logger->debug($message, $context);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }
}
