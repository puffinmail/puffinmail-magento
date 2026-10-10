<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

/**
 * Internal to OutboxProcessor: stop sending for a store view in this run.
 */
class StopStoreException extends \RuntimeException
{
}
