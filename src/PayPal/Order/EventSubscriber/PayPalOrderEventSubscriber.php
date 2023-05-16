<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace PrestaShop\Module\PrestashopCheckout\PayPal\Order\EventSubscriber;

use PrestaShop\Module\PrestashopCheckout\Exception\PsCheckoutException;
use PrestaShop\Module\PrestashopCheckout\Order\Command\UpdateOrderStatusCommand;
use PrestaShop\Module\PrestashopCheckout\Order\Command\UpdatePayPalOrderMatriceCommand;
use PrestaShop\Module\PrestashopCheckout\Order\Exception\OrderException;
use PrestaShop\Module\PrestashopCheckout\Order\State\Exception\OrderStateException;
use PrestaShop\Module\PrestashopCheckout\Order\State\Query\GetOrderStateConfigurationQuery;
use PrestaShop\Module\PrestashopCheckout\Order\State\ValueObject\OrderStateId;
use PrestaShop\Module\PrestashopCheckout\Order\ValueObject\OrderId;
use PrestaShop\Module\PrestashopCheckout\PayPal\Card3DSecure;
use PrestaShop\Module\PrestashopCheckout\PayPal\Order\Command\CapturePayPalOrderCommand;
use PrestaShop\Module\PrestashopCheckout\PayPal\Order\Command\PrunePayPalOrderCacheCommand;
use PrestaShop\Module\PrestashopCheckout\PayPal\Order\Command\SavePayPalOrderCommand;
use PrestaShop\Module\PrestashopCheckout\PayPal\Order\Command\UpdatePayPalOrderCacheCommand;
use PrestaShop\Module\PrestashopCheckout\PayPal\Order\Event\PayPalOrderApprovalReversedEvent;
use PrestaShop\Module\PrestashopCheckout\PayPal\Order\Event\PayPalOrderApprovedEvent;
use PrestaShop\Module\PrestashopCheckout\PayPal\Order\Event\PayPalOrderCompletedEvent;
use PrestaShop\Module\PrestashopCheckout\PayPal\Order\Event\PayPalOrderCreatedEvent;
use PrestaShop\Module\PrestashopCheckout\PayPal\Order\Event\PayPalOrderEvent;
use PrestaShop\Module\PrestashopCheckout\PayPal\Order\Event\PayPalOrderFetchedEvent;
use PrestaShop\Module\PrestashopCheckout\PayPal\Order\Event\PayPalOrderNotApprovedEvent;
use PrestaShop\Module\PrestashopCheckout\PayPal\Order\Exception\PayPalOrderException;
use PrestaShop\Module\PrestashopCheckout\Repository\PsCheckoutCartRepository;
use PrestaShop\Module\PrestashopCheckout\Session\Command\UpdatePsCheckoutSessionCommand;
use Ps_checkout;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PayPalOrderEventSubscriber implements EventSubscriberInterface
{
    /**
     * @var Ps_checkout
     */
    private $module;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PsCheckoutCartRepository
     */
    private $psCheckoutCartRepository;

    /**
     * @param Ps_checkout $module
     * @param LoggerInterface $logger
     * @param PsCheckoutCartRepository $psCheckoutCartRepository
     */
    public function __construct(
        Ps_checkout $module,
        LoggerInterface $logger,
        PsCheckoutCartRepository $psCheckoutCartRepository
    ) {
        $this->module = $module;
        $this->logger = $logger;
        $this->psCheckoutCartRepository = $psCheckoutCartRepository;
    }

    /**
     * @return array[]
     */
    public static function getSubscribedEvents()
    {
        return [
            PayPalOrderCreatedEvent::class => [
                ['savePayPalOrder'],
                ['prunePayPalOrderCache'],
            ],
            PayPalOrderApprovedEvent::class => [
                ['savePayPalOrder'],
                ['capturePayPalOrder'],
                ['prunePayPalOrderCache'],
            ],
            PayPalOrderNotApprovedEvent::class => [
                ['savePayPalOrder'],
            ],
            PayPalOrderCompletedEvent::class => [
                ['savePayPalOrder'],
                ['updatePayPalOrderMatrice'],
                ['prunePayPalOrderCache'],
            ],
            PayPalOrderApprovalReversedEvent::class => [
                ['savePayPalOrder'],
                ['prunePayPalOrderCache'],
            ],
            PayPalOrderFetchedEvent::class => [
                ['updatePayPalOrderCache'],
            ],
        ];
    }

    /**
     * @param $event
     *
     * @return void
     *
     * @throws PayPalOrderException
     * @throws PsCheckoutException
     * @throws \PrestaShopException
     * @throws \PrestaShop\Module\PrestashopCheckout\Cart\Exception\CartException
     */
    public function savePayPalOrder($event)
    {
        // @todo We don't have a dedicated table for order data storage in database yet
        // But we can save some data in current pscheckout_cart table

        $psCheckoutCart = $this->psCheckoutCartRepository->findOneByPayPalOrderId($event->getOrderPayPalId()->getValue());

        if (false === $psCheckoutCart) {
            throw new PsCheckoutException(sprintf('order #%s is not linked to a cart', $event->getOrderPayPalId()->getValue()), PsCheckoutException::PRESTASHOP_CART_NOT_FOUND);
        }

        switch (get_class($event)) {
            case PayPalOrderCreatedEvent::class:
                $orderStatus = 'CREATED';
                break;
            case PayPalOrderApprovedEvent::class:
                $orderStatus = 'APPROVED';
                break;
            case PayPalOrderCompletedEvent::class:
                $orderStatus = 'COMPLETED';
                break;
            case PayPalOrderApprovalReversedEvent::class:
                $orderStatus = 'PENDING_APPROVAL';
                break;
            case PayPalOrderNotApprovedEvent::class:
                $orderStatus = 'PENDING';
                break;
            default:
                $orderStatus = '';
        }

        // COMPLETED is a final status, always ensure we don't update to previous status due to outdated webhook for example
        if ($psCheckoutCart->getPaypalStatus() === 'COMPLETED') {
            return;
        }

        if ($psCheckoutCart->getPaypalStatus() !== $orderStatus || $psCheckoutCart->getDateUpd() < date_create_from_format('Y-m-d\TH:i:s\Z', $event->getOrderPayPal()['update_time'])) {
            $this->module->getService('ps_checkout.bus.command')->handle(new SavePayPalOrderCommand(
                $event->getOrderPayPalId()->getValue(),
                $orderStatus,
                $event->getOrderPayPal()
            ));
        }
    }

    /**
     * @param PayPalOrderApprovedEvent $event
     *
     * @return void
     *
     * @throws PsCheckoutException
     * @throws \PrestaShopException
     * @throws PayPalOrderException
     * @throws \Exception
     */
    public function capturePayPalOrder(PayPalOrderApprovedEvent $event)
    {
        $psCheckoutCart = $this->psCheckoutCartRepository->findOneByPayPalOrderId($event->getOrderPayPalId()->getValue());

        if (false === $psCheckoutCart) {
            throw new PsCheckoutException(sprintf('order #%s is not linked to a cart', $event->getOrderPayPalId()->getValue()), PsCheckoutException::PRESTASHOP_CART_NOT_FOUND);
        }

        // ExpressCheckout require buyer select a delivery option, we have to check if cart is ready to payment
        if ($psCheckoutCart->isExpressCheckout() && $psCheckoutCart->getPaypalFundingSource() === 'paypal') {
            $this->logger->info('PayPal Order cannot be captured.');

            return;
        }

        // @todo Always check if Cart is ready to payment before (quantities, stocks, invoice address, delivery address, delivery option...)

        $orderPayPal = $event->getOrderPayPal();
        if ($psCheckoutCart->isHostedFields()) {
            $card3DSecure = (new Card3DSecure())->continueWithAuthorization($orderPayPal);

            $this->logger->info(
                '3D Secure authentication result',
                [
                    'authentication_result' => isset($order['payment_source']['card']['authentication_result']) ? $order['payment_source']['card']['authentication_result'] : null,
                    'decision' => str_replace(
                        [
                            (string) Card3DSecure::NO_DECISION,
                            (string) Card3DSecure::PROCEED,
                            (string) Card3DSecure::REJECT,
                            (string) Card3DSecure::RETRY,
                        ],
                        [
                            \Configuration::get('PS_CHECKOUT_LIABILITY_SHIFT_REQ') ? 'Rejected, no liability shift' : 'Proceed, without liability shift',
                            'Proceed, liability shift is possible',
                            'Rejected',
                            'Retry, ask customer to retry',
                        ],
                        (string) $card3DSecure
                    ),
                ]
            );

            switch ($card3DSecure) {
                case Card3DSecure::REJECT:
                    throw new PsCheckoutException('Card Strong Customer Authentication failure', PsCheckoutException::PAYPAL_PAYMENT_CARD_SCA_FAILURE);
                case Card3DSecure::RETRY:
                    throw new PsCheckoutException('Card Strong Customer Authentication must be retried.', PsCheckoutException::PAYPAL_PAYMENT_CARD_SCA_UNKNOWN);
                case Card3DSecure::NO_DECISION:
                    if (\Configuration::get('PS_CHECKOUT_LIABILITY_SHIFT_REQ')) {
                        throw new PsCheckoutException('No liability shift to card issuer', PsCheckoutException::PAYPAL_PAYMENT_CARD_SCA_UNKNOWN);
                    }
                    break;
            }
        }

        // Check if PayPal order amount is the same than the cart amount : we tolerate a difference of more or less 0.05
        $paypalOrderAmount = (float) sprintf('%01.2f', $orderPayPal['purchase_units'][0]['amount']['value']);
        $cartAmount = (float) sprintf('%01.2f', (new \Cart($psCheckoutCart->getIdCart()))->getOrderTotal(true, \Cart::BOTH));

        if ($paypalOrderAmount + 0.05 < $cartAmount || $paypalOrderAmount - 0.05 > $cartAmount) {
            throw new PsCheckoutException('The transaction amount does not match with the cart amount.', PsCheckoutException::DIFFERENCE_BETWEEN_TRANSACTION_AND_CART);
        }

        // This should mainly occur for APMs
        $this->module->getService('ps_checkout.bus.command')->handle(
            new CapturePayPalOrderCommand(
                $event->getOrderPayPalId()->getValue(),
                $psCheckoutCart->getPaypalFundingSource()
            )
        );
    }

    /**
     * @param PayPalOrderEvent $event
     *
     * @return void
     *
     * @throws PayPalOrderException
     */
    public function updatePayPalOrderCache(PayPalOrderEvent $event)
    {
        $this->module->getService('ps_checkout.bus.command')->handle(new UpdatePayPalOrderCacheCommand(
            $event->getOrderPayPalId()->getValue(),
            $event->getOrderPayPal()
        ));
    }

    /**
     * @param PayPalOrderEvent $event
     *
     * @return void
     *
     * @throws PayPalOrderException
     */
    public function prunePayPalOrderCache(PayPalOrderEvent $event)
    {
        $this->module->getService('ps_checkout.bus.command')->handle(
            new PrunePayPalOrderCacheCommand($event->getOrderPayPalId()->getValue())
        );
    }

    /**
     * @param PayPalOrderCompletedEvent $event
     *
     * @return void
     *
     * @throws PayPalOrderException
     */
    public function updatePayPalOrderMatrice(PayPalOrderCompletedEvent $event)
    {
        $this->module->getService('ps_checkout.bus.command')->handle(
            new UpdatePayPalOrderMatriceCommand($event->getOrderPayPalId()->getValue())
        );
    }


    // @TODO : I think this method should be removed
    /**
     * @param PayPalOrderCompletedEvent $event
     *
     * @return void
     *
     * @throws OrderException
     * @throws OrderStateException
     */
    public function updateOrderStatus(PayPalOrderCompletedEvent $event)
    {
        $getOrderStateConfiguration = $this->module->getService('ps_checkout.bus.command')->handle(new GetOrderStateConfigurationQuery());
        $orderId = new OrderId($event->getOrderPayPalId()->getValue());
        // TODO: Retrieve current state
        $currentOrderState = $getOrderStateConfiguration->getKeyById(new OrderStateId($event->getOrderPayPal()->getCurrentState()));
        $this->module->getService('ps_checkout.bus.command')->handle(
            new UpdateOrderStatusCommand($order['id'], $currentOrderState)
        );
    }
}
