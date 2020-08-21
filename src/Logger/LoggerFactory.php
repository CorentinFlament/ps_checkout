<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace PrestaShop\Module\PrestashopCheckout\Logger;

use Monolog\Handler\HandlerInterface;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use PrestaShop\Module\PrestashopCheckout\Exception\PsCheckoutException;
use PrestaShop\Module\PrestashopCheckout\Sentry\SentryHandler;
use PrestaShop\Module\PrestashopCheckout\Sentry\SentryProcessor;
use Psr\Log\LoggerInterface;

/**
 * Class responsible for create Logger instance.
 */
class LoggerFactory
{
    const PS_CHECKOUT_LOGGER_MAX_FILES = 'PS_CHECKOUT_LOGGER_MAX_FILES';
    const PS_CHECKOUT_LOGGER_LEVEL = 'PS_CHECKOUT_LOGGER_LEVEL';
    const PS_CHECKOUT_LOGGER_HTTP = 'PS_CHECKOUT_LOGGER_HTTP';
    const PS_CHECKOUT_LOGGER_HTTP_FORMAT = 'PS_CHECKOUT_LOGGER_HTTP_FORMAT';

    /**
     * @var string
     */
    private $name;

    /**
     * @var HandlerInterface
     */
    private $loggerHandler;

    /**
     * @var SentryHandler|null
     */
    private $sentryHandler;

    /**
     * @param string $name
     * @param HandlerInterface $loggerHandler
     *
     * @throws PsCheckoutException
     */
    public function __construct($name, HandlerInterface $loggerHandler, SentryHandler $sentryHandler = null)
    {
        $this->assertNameIsValid($name);
        $this->name = $name;
        $this->loggerHandler = $loggerHandler;
        $this->sentryHandler = $sentryHandler;
    }

    /**
     * @return LoggerInterface
     */
    public function build()
    {
        return new Logger(
            $this->name,
            [
                $this->loggerHandler,
                $this->sentryHandler->getHandler(),
            ],
            [
                new PsrLogMessageProcessor(),
                new SentryProcessor(),
            ]
        );
    }

    /**
     * @param string $name
     *
     * @throws PsCheckoutException
     */
    private function assertNameIsValid($name)
    {
        if (empty($name)) {
            throw new PsCheckoutException('Logger name cannot be empty.', PsCheckoutException::UNKNOWN);
        }

        if (false === is_string($name)) {
            throw new PsCheckoutException('Logger name should be a string.', PsCheckoutException::UNKNOWN);
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $name)) {
            throw new PsCheckoutException('Logger name is invalid.', PsCheckoutException::UNKNOWN);
        }
    }
}
