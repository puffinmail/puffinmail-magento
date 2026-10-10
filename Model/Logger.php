<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Psr\Log\LoggerInterface;

/**
 * Logs to var/log/puffinmail.log (see di.xml). Debug lines only when Advanced > Debug Log is on.
 */
class Logger
{
    /**
     * Logger constructor.
     *
     * @param LoggerInterface $logger
     * @param Config $config
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
    }

    /**
     * Logs a debug line, only when Advanced > Debug Log is on.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        if ($this->config->isDebug()) {
            $this->logger->debug($message, $context);
        }
    }

    /**
     * Logs an info line.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    /**
     * Logs a warning.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    /**
     * Logs an error.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }
}
