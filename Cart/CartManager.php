<?php
/*
 * This file is part of the Sulu CMS.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\Sales\OrderBundle\Cart;

use Doctrine\Common\Persistence\ObjectManager;
use Sulu\Component\Security\Authentication\UserInterface;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Sulu\Bundle\ContactBundle\Entity\Contact;
use Sulu\Bundle\Sales\CoreBundle\Manager\BaseSalesManager;
use Sulu\Bundle\Sales\CoreBundle\Manager\OrderAddressManager;
use Sulu\Bundle\Sales\CoreBundle\Pricing\GroupedItemsPriceCalculatorInterface;
use Sulu\Bundle\Sales\OrderBundle\Api\ApiOrderInterface;
use Sulu\Bundle\Sales\OrderBundle\Entity\OrderInterface;
use Sulu\Bundle\Sales\OrderBundle\Api\Order as ApiOrder;
use Sulu\Bundle\Sales\OrderBundle\Entity\Order;
use Sulu\Bundle\Sales\OrderBundle\Entity\OrderRepository;
use Sulu\Bundle\Sales\OrderBundle\Entity\OrderStatus;
use Sulu\Bundle\Sales\OrderBundle\Order\Exception\OrderException;
use Sulu\Bundle\Sales\OrderBundle\Order\OrderFactoryInterface;
use Sulu\Bundle\Sales\OrderBundle\Order\OrderManager;
use Sulu\Bundle\Sales\OrderBundle\Order\OrderPdfManager;
use Sulu\Component\Persistence\RelationTrait;

class CartManager extends BaseSalesManager
{
    use RelationTrait;

    const CART_STATUS_OK = 1;
    const CART_STATUS_ERROR = 2;
    const CART_STATUS_PRICE_CHANGED = 3;
    const CART_STATUS_PRODUCT_REMOVED = 4;
    const CART_STATUS_ORDER_LIMIT_EXCEEDED = 5;

    /**
     * TODO: replace by config
     *
     * defines when a cart expires
     */
    const EXPIRY_MONTHS = 2;

    /**
     * @var ObjectManager
     */
    protected $em;

    /**
     * @var SessionInterface
     */
    protected $session;

    /**
     * @var OrderManager
     */
    protected $orderManager;

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var GroupedItemsPriceCalculatorInterface
     */
    protected $priceCalculation;

    /**
     * @var string
     */
    protected $defaultCurrency;

    /**
     * @var AccountManager
     */
    protected $accountManager;

    /**
     * @var OrderPdfManager
     */
    protected $pdfManager;

    /**
     * @var \Swift_Mailer
     */
    protected $mailer;

    /**
     * @var string
     */
    protected $emailFrom;

    /**
     * @var string
     */
    protected $emailConfirmationTo;

    /**
     * @var OrderFactoryInterface
     */
    protected $orderFactory;

    /**
     * @var OrderAddressManager
     */
    protected $orderAddressManager;

    /**
     * @param ObjectManager $em
     * @param SessionInterface $session
     * @param OrderRepository $orderRepository
     * @param OrderManager $orderManager
     * @param GroupedItemsPriceCalculatorInterface $priceCalculation
     * @param string $defaultCurrency
     * @param AccountManager $accountManager
     * @param \Twig_Environment $twig
     * @param OrderPdfManager $pdfManager
     * @param \Swift_Mailer $mailer
     * @param OrderFactoryInterface $orderFactory
     * @param string $emailFrom
     * @param string $emailConfirmationTo
     * @param OrderAddressManager $orderAddressManager
     */
    public function __construct(
        ObjectManager $em,
        SessionInterface $session,
        OrderRepository $orderRepository,
        OrderManager $orderManager,
        GroupedItemsPriceCalculatorInterface $priceCalculation,
        $defaultCurrency,
        $accountManager,
        \Twig_Environment $twig,
        OrderPdfManager $pdfManager,
        \Swift_Mailer $mailer,
        OrderFactoryInterface $orderFactory,
        $emailFrom,
        $emailConfirmationTo,
        OrderAddressManager $orderAddressManager
    ) {
        $this->em = $em;
        $this->session = $session;
        $this->orderRepository = $orderRepository;
        $this->orderManager = $orderManager;
        $this->priceCalculation = $priceCalculation;
        $this->defaultCurrency = $defaultCurrency;
        $this->accountManager = $accountManager;
        $this->twig = $twig;
        $this->pdfManager = $pdfManager;
        $this->mailer = $mailer;
        $this->orderFactory = $orderFactory;
        $this->emailFrom = $emailFrom;
        $this->emailConfirmationTo = $emailConfirmationTo;
        $this->orderAddressManager = $orderAddressManager;
    }

    /**
     * @param $user
     * @param null|string $locale
     * @param null|string $currency
     * @param bool $persistEmptyCart Define if an empty cart should be persisted
     * @param bool $updatePrices Defines if prices should be updated
     *
     * @return null|ApiOrder
     */
    public function getUserCart(
        $user = null,
        $locale = null,
        $currency = null,
        $persistEmptyCart = false,
        $updatePrices = false
    ) {
        // cart by session ID
        if (!$user) {
            // TODO: get correct locale
            $locale = 'de';
            $cartsArray = $this->findCartBySessionId();
        } else {
            // TODO: check if cart for this sessionId exists and assign it to user

            // default locale from user
            $locale = $locale ?: $user->getLocale();
            // get carts
            $cartsArray = $this->findCartsByUser($user, $locale);
        }

        // cleanup cart array: remove duplicates and expired carts
        $this->cleanupCartsArray($cartArray);

        // check if cart exists
        if ($cartsArray && count($cartsArray) > 0) {
            // multiple carts found, do a cleanup
            $cart = $cartsArray[0];
        } else {
            // user has no cart - return empty one
            $cart = $this->createEmptyCart($user, $persistEmptyCart);
        }

        // check if all products are still available
        $cartNoRemovedProducts = $this->checkProductsAvailability($cart);

        // create api entity
        $apiOrder = $this->orderFactory->createApiEntity($cart, $locale);

        if (!$cartNoRemovedProducts) {
            $apiOrder->addCartErrorCode(self::CART_STATUS_PRODUCT_REMOVED);
        }

        $this->orderManager->updateApiEntity($apiOrder, $locale);

        // check if prices have changed
        if ($apiOrder->hasChangedPrices()) {
            $apiOrder->addCartErrorCode(self::CART_STATUS_PRICE_CHANGED);
        }

        if ($updatePrices) {
            $this->updateCartPrices($apiOrder->getItems());
        }

        return $apiOrder;
    }

    /**
     * Updates changed prices
     *
     * @param $items
     *
     * @return bool
     */
    public function updateCartPrices($items)
    {
        // set prices to changed
        $hasChanged = $this->priceCalculation->setPricesOfChanged($items);
        if ($hasChanged) {
            $this->em->flush();
        }

        return $hasChanged;
    }

    /**
     * Updates the cart
     *
     * @param array $data
     * @param UserInterface $user
     * @param string $locale
     *
     * @throws \Sulu\Bundle\Sales\OrderBundle\Order\Exception\OrderException
     * @throws \Sulu\Bundle\Sales\OrderBundle\Order\Exception\OrderNotFoundException
     *
     * @return null|Order
     */
    public function updateCart($data, $user, $locale)
    {
        $cart = $this->getUserCart($user, $locale);
        $userId = $user ? $user->getId() : null;
        $this->orderManager->save($data, $locale, $userId, $cart->getId());

        return $cart;
    }

    /**
     * Submits an order
     *
     * @param UserInterface $user
     * @param string $locale
     * @param bool $orderWasSubmitted
     * @param OrderInterface $originalCart The original cart that was submitted
     *
     * @throws OrderException
     * @throws \Sulu\Component\Rest\Exception\EntityNotFoundException
     *
     * @return null|ApiOrder
     */
    public function submit($user, $locale, &$orderWasSubmitted = true, &$originalCart = null)
    {
        $orderWasSubmitted = true;

        $cart = $this->getUserCart($user, $locale, null, false, true);
        if (count($cart->getCartErrorCodes()) > 0) {
            $orderWasSubmitted = false;

            return $cart;
        } else {
            if (count($cart->getItems()) < 1) {
                throw new OrderException('Empty Cart');
            }

            // set order-date to current date
            $cart->setOrderDate(new \DateTime());

            // TODO: check if user hasn't exceeded order limit
            // TODO: move this functionality to
            $this->userCartSubmissionLimitExceeded($user, $cart, $locale);


            // change status of order to confirmed
            $this->orderManager->convertStatus($cart, OrderStatus::STATUS_CONFIRMED);

            // order-addresses have to be set to the current contact-addresses
            $this->reApplyOrderAddresses($cart, $user);

            $customer = $user->getContact();

            // send confirmation email to customer
            $this->sendConfirmationEmail(
                $customer->getMainEmail(),
                $cart,
                'SuluSalesOrderBundle:Emails:customer.order.confirmation.twig',
                $customer
            );

            // get responsible person of contacts account
            if ($customer->getMainAccount() &&
                $customer->getMainAccount()->getResponsiblePerson() &&
                $customer->getMainAccount()->getResponsiblePerson()->getMainEmail()
            ) {
                $shopOwnerEmail = $customer->getMainAccount()->getResponsiblePerson()->getMainEmail();
            } else {
                $shopOwnerEmail = $this->emailConfirmationTo;
            }

            // send confirmation email to shop owner
            $this->sendConfirmationEmail(
                $shopOwnerEmail,
                $cart,
                'SuluSalesOrderBundle:Emails:shopowner.order.confirmation.twig'
            );
        }

        // flush on success
        $this->em->flush();

        $originalCart = $cart;

        return $this->getUserCart($user, $locale);
    }

    /**
     * Checks if a cart value does not exceed ceiling for user
     */
    protected function userCartSubmissionLimitExceeded(UserInterface $user, ApiOrderInterface $cart, $locale)
    {
        return false;
    }

    /**
     * Removes items from cart that have no valid shop products
     * applied; and returns if all products are still available
     *
     * @param OrderInterface $cart
     *
     * @return bool If all products are available
     */
    private function checkProductsAvailability(OrderInterface $cart)
    {
        // no check needed
        if ($cart->getItems()->isEmpty()) {
            return true;
        }

        $containsInvalidProducts = false;
        /** @var \Sulu\Bundle\Sales\CoreBundle\Entity\ItemInterface $item */
        foreach ($cart->getItems() as $item) {
            if (!$item->getProduct() ||
                !$item->getProduct()->isValidShopProduct()
            ) {
                $containsInvalidProducts = true;
                $cart->removeItem($item);
                $this->em->remove($item);
            }
        }
        // persist new cart
        if ($containsInvalidProducts) {
            $this->em->flush();
        }

        return !$containsInvalidProducts;
    }

    /**
     * Reapplies order-addresses on submit
     *
     * @param ApiOrderInterface $cart
     */
    private function reApplyOrderAddresses($cart, $user)
    {
        // validate addresses
        $this->validateOrCreateAddresses($cart, $user);

        // reapply invoice address of cart
        if ($cart->getInvoiceAddress()->getContactAddress()) {
            $this->orderAddressManager->getAndSetOrderAddressByContactAddress(
                $cart->getInvoiceAddress()->getContactAddress(),
                null,
                null,
                $cart->getInvoiceAddress()
            );
        }

        // reapply delivery address of cart
        if ($cart->getDeliveryAddress()->getContactAddress()) {
            $this->orderAddressManager->getAndSetOrderAddressByContactAddress(
                $cart->getDeliveryAddress()->getContactAddress(),
                null,
                null,
                $cart->getDeliveryAddress()
            );
        }

        // reapply delivery-addresses of every item
        foreach ($cart->getItems() as $item) {
            if ($item->getDeliveryAddress() &&
                $item->getDeliveryAddress()->getContactAddress()
            ) {
                $this->orderAddressManager->getAndSetOrderAddressByContactAddress(
                    $item->getDeliveryAddress()->getContactAddress(),
                    null,
                    null,
                    $item->getDeliveryAddress()
                );
            }
        }
    }

    /**
     * Checks if addresses have been set and sets new ones
     *
     * @param ApiOrderInterface $cart
     */
    protected function validateOrCreateAddresses($cart)
    {
        if ($cart instanceof ApiOrderInterface) {
            $cart = $cart->getEntity();
        }
        if (!$cart->getDeliveryAddress() || !$cart->getInvoiceAddress()) {
            $addresses = $cart->getCustomerAccount()->getAccountAddresses();
            if ($addresses->isEmpty()) {
                throw new Exception('customer has no addresses');
            }
            $mainAddress = $cart->getCustomerAccount()->getMainAddress();
            if (!$mainAddress) {
                throw new Exception('customer has no main-address');
            }

            if (!$cart->getDeliveryAddress()) {
                $newAddress = $this->orderAddressManager->getAndSetOrderAddressByContactAddress(
                    $mainAddress,
                    $cart->getCustomerContact(),
                    $cart->getCustomerAccount()
                );
                $cart->setDeliveryAddress($newAddress);
                $this->em->persist($newAddress);
            }
            if (!$cart->getInvoiceAddress()) {
                $newAddress = $this->orderAddressManager->getAndSetOrderAddressByContactAddress(
                    $mainAddress,
                    $cart->getCustomerContact(),
                    $cart->getCustomerAccount()
                );
                $cart->setInvoiceAddress($newAddress);
                $this->em->persist($newAddress);
            }
        }
    }

    /**
     * Finds cart by session-id
     *
     * @return array
     */
    private function findCartBySessionId()
    {
        $sessionId = $this->session->getId();
        $cartsArray = $this->orderRepository->findBy(
            array(
                'sessionId' => $sessionId,
                'status' => OrderStatus::STATUS_IN_CART
            ),
            array(
                'created' => 'DESC'
            )
        );

        return $cartsArray;
    }

    /**
     * Finds cart by locale and user
     *
     * @param string $locale
     * @param UserInterface $user
     *
     * @return array|null
     */
    private function findCartsByUser($user, $locale)
    {
        $cartsArray = $this->orderRepository->findByStatusIdsAndUser(
            $locale,
            array(OrderStatus::STATUS_IN_CART, OrderStatus::STATUS_CART_PENDING),
            $user
        );

        return $cartsArray;
    }

    /**
     * removes all elements from database but the first
     *
     * @param $cartsArray
     */
    private function cleanupCartsArray(&$cartsArray)
    {
        if ($cartsArray && count($cartsArray) > 0) {
            // handle cartsArray count is > 1
            foreach ($cartsArray as $index => $cart) {
                // delete expired carts
                if ($cart->getChanged()->getTimestamp() < strtotime(static::EXPIRY_MONTHS . ' months ago')) {
//                    $this->em->remove($cart);
                    continue;
                }

                // dont delete first element, since this is the current cart
                if ($index === 0) {
                    continue;
                }
                // remove duplicated carts
//                $this->em->remove($cart);
            }
        }
    }

    /**
     * Adds a product to cart
     *
     * @param $data
     * @param null $user
     * @param null $locale
     *
     * @return null|Order
     */
    public function addProduct($data, $user = null, $locale = null)
    {
        //TODO: locale
        // get cart
        $cart = $this->getUserCart($user, $locale, null, true);
        // define user-id
        $userId = $user ? $user->getId() : null;

        $this->orderManager->addItem($data, $locale, $userId, $cart);

        $this->orderManager->updateApiEntity($cart, $locale);

        return $cart;
    }

    /**
     * Update item data
     *
     * @param int $itemId
     * @param array $data
     * @param null|UserInterface $user
     * @param null|string $locale
     *
     * @throws ItemNotFoundException
     *
     * @return null|Order
     */
    public function updateItem($itemId, $data, $user = null, $locale = null)
    {
        $cart = $this->getUserCart($user, $locale);
        $userId = $user ? $user->getId() : null;

        $item = $this->orderManager->getOrderItemById($itemId, $cart->getEntity());

        $this->orderManager->updateItem($item, $data, $locale, $userId);

        $this->orderManager->updateApiEntity($cart, $locale);

        return $cart;
    }

    /**
     * Removes an item from cart
     *
     * @param int $itemId
     * @param null|UserInterface $user
     * @param null|string $locale
     *
     * @return null|Order
     */
    public function removeItem($itemId, $user = null, $locale = null)
    {
        $cart = $this->getUserCart($user, $locale);

        $item = $this->orderManager->getOrderItemById($itemId, $cart->getEntity(), $hasMultiple);

        $this->orderManager->removeItem($item, $cart->getEntity(), !$hasMultiple);

        $this->orderManager->updateApiEntity($cart, $locale);

        return $cart;
    }

    /**
     * Function creates an empty cart
     * this means an order with status 'in_cart' is created and all necessary data is set
     *
     * @param $user
     * @param $persist
     * @param null|string $currencyCode
     *
     * @return Order
     * @throws \Sulu\Component\Rest\Exception\EntityNotFoundException
     */
    protected function createEmptyCart($user, $persist, $currencyCode = null)
    {
        $cart = new Order();
        $cart->setCreator($user);
        $cart->setChanger($user);
        $cart->setCreated(new \DateTime());
        $cart->setChanged(new \DateTime());
        $cart->setOrderDate(new \DateTime());

        // set currency - if not defined use default
        $currencyCode = $currencyCode ?: $this->defaultCurrency;
        $cart->setCurrencyCode($currencyCode);

        // get address from contact and account
        $contact = $user->getContact();
        $account = $contact->getMainAccount();
        $cart->setCustomerContact($contact);
        $cart->setCustomerAccount($account);

        /** Account $account */
        if ($account && $account->getResponsiblePerson()) {
            $cart->setResponsibleContact($account->getResponsiblePerson());
        }

        $addressSource = $contact;
        if ($account) {
            $addressSource = $account;
        }
        // get billing address
        $invoiceOrderAddress = null;
        $invoiceAddress = $this->accountManager->getBillingAddress($addressSource, true);
        if ($invoiceAddress) {
            // convert to order-address
            $invoiceOrderAddress = $this->orderAddressManager->getAndSetOrderAddressByContactAddress(
                $invoiceAddress,
                $contact,
                $account
            );
            $cart->setInvoiceAddress($invoiceOrderAddress);
        }
        $deliveryOrderAddress = null;
        $deliveryAddress = $this->accountManager->getDeliveryAddress($addressSource, true);
        if ($deliveryAddress) {
            // convert to order-address
            $deliveryOrderAddress = $this->orderAddressManager->getAndSetOrderAddressByContactAddress(
                $deliveryAddress,
                $contact,
                $account
            );
            $cart->setDeliveryAddress($deliveryOrderAddress);
        }

        // TODO: anonymous order
        if ($user) {
            $name = $user->getContact()->getFullName();
        } else {
            $name = 'Anonymous';
        }
        $cart->setCustomerName($name);

        $this->orderManager->convertStatus($cart, OrderStatus::STATUS_IN_CART, false, $persist);

        if ($persist) {
            $this->em->persist($cart);
            if ($invoiceOrderAddress) {
                $this->em->persist($invoiceOrderAddress);
            }
            if ($deliveryOrderAddress) {
                $this->em->persist($deliveryOrderAddress);
            }
        }

        return $cart;
    }

    /**
     * Returns array containing number of items and total-price
     * array('totalItems', 'totalPrice')
     *
     * @param $user
     * @param $locale
     *
     * @return array
     */
    public function getNumberItemsAndTotalPrice($user, $locale)
    {
        $cart = $this->getUserCart($user, $locale);

        return array(
            'totalItems' => count($cart->getItems()),
            'totalPrice' => $cart->getTotalNetPrice(),
            'totalPriceFormatted' => $cart->getTotalNetPriceFormatted(),
            'currency' => $cart->getCurrencyCode()
        );
    }

    /**
     *
     *
     * @param string $recipient The email-address of the customer
     * @param ApiOrderInterface $apiOrder
     * @param string $templatePath Template to render
     * @param Contact|null $customerContact
     *
     * @return bool
     */
    public function sendConfirmationEmail($recipient, $apiOrder, $templatePath, Contact $customerContact = null)
    {
        if (empty($recipient)) {
            return false;
        }

        $tmplData = array(
            'order' => $apiOrder,
            'contact' => $customerContact
        );

        $template = $this->twig->loadTemplate($templatePath);
        $subject = $template->renderBlock('subject', $tmplData);

        $emailBodyText = $template->renderBlock('body_text', $tmplData);
        $emailBodyHtml = $template->renderBlock('body_html', $tmplData);

        $pdf = $this->pdfManager->createOrderConfirmation($apiOrder);
        $pdfFileName = $this->pdfManager->getPdfName($apiOrder);

        if ($recipient) {
            // now send mail
            $attachment = \Swift_Attachment::newInstance()
                ->setFilename($pdfFileName)
                ->setContentType('application/pdf')
                ->setBody($pdf);

            /** @var \Swift_Message $message */
            $message = \Swift_Message::newInstance()
                ->setSubject($subject)
                ->setFrom($this->emailFrom)
                ->setTo($recipient)
                ->setBody($emailBodyText, 'text/plain')
                ->addPart($emailBodyHtml, 'text/html')
                ->attach($attachment);

            return $this->mailer->send($message);
        }

        return false;
    }
}
