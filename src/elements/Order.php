<?php

namespace craft\commerce\elements;

use Craft;
use craft\commerce\base\Element;
use craft\commerce\base\Gateway;
use craft\commerce\base\GatewayInterface;
use craft\commerce\base\ShippingMethodInterface;
use craft\commerce\elements\actions\UpdateOrderStatus;
use craft\commerce\elements\db\OrderQuery;
use craft\commerce\events\OrderEvent;
use craft\commerce\helpers\Currency;
use craft\commerce\models\Address;
use craft\commerce\models\Customer;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\models\OrderHistory;
use craft\commerce\models\OrderSettings;
use craft\commerce\models\OrderStatus;
use craft\commerce\models\ShippingMethod;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin;
use craft\commerce\records\Order as OrderRecord;
use craft\commerce\records\OrderAdjustment as OrderAdjustmentRecord;
use craft\elements\actions\Delete;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use craft\web\View;
use yii\base\Exception;

/**
 * Order or Cart model.
 *
 * @property int                     $id
 * @property string                  $number
 * @property string                  $couponCode
 * @property float                   $itemTotal
 * @property float                   $totalPrice
 * @property float                   $totalPaid
 * @property string                  $email
 * @property bool                    $isCompleted
 * @property \DateTime               $dateOrdered
 * @property string                  $currency
 * @property string                  $paymentCurrency
 * @property \DateTime               $datePaid
 * @property string                  $lastIp
 * @property string                  $orderLocale
 * @property string                  $message
 * @property string                  $returnUrl
 * @property string                  $cancelUrl
 * @property int                     $billingAddressId
 * @property int                     $shippingAddressId
 * @property ShippingMethodInterface $shippingMethod
 * @property string                  $shippingMethodHandle
 * @property int                     $gatewayId
 * @property int                     $customerId
 * @property int                     $orderStatusId
 * @property int                     $totalQty
 * @property int                     $totalWeight
 * @property int                     $totalHeight
 * @property int                     $totalLength
 * @property int                     $totalWidth
 * @property int                     $totalTax
 * @property int                     $totalShippingCost
 * @property int                     $totalDiscount
 * @property string                  $pdfUrl
 * @property OrderSettings           $type
 * @property LineItem[]              $lineItems
 * @property Address                 $billingAddress
 * @property Customer                $customer
 * @property Address                 $shippingAddress
 * @property OrderAdjustment[]       $adjustments
 * @property Gateway                 $gateway
 * @property Transaction[]           $transactions
 * @property OrderStatus             $orderStatus
 * @property null|string             $name
 * @property string                  $shortNumber
 * @property ShippingMethodInterface $shippingMethodId
 * @property float                   $totalTaxIncluded
 * @property float|int               $adjustmentSubtotal
 * @property int                     $totalSaleAmount
 * @property int                     $itemSubtotal
 * @property OrderHistory[]          $histories
 * @property int                     $baseShippingCost
 * @property array|Transaction[]     $nestedTransactions
 * @property float                   $adjustmentsTotal
 * @property float                   $totalTaxablePrice
 * @property bool                    $shouldRecalculateAdjustments
 * @property array                   $orderAdjustments
 * @property float                   baseDiscount
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   https://craftcommerce.com/license Craft Commerce License Agreement
 * @see       https://craftcommerce.com
 * @package   craft.plugins.commerce.models
 * @since     1.0
 */
class Order extends Element
{
    /**
     * @event OrderEvent This event is raised when an order is completed
     */
    const EVENT_BEFORE_COMPLETE_ORDER = 'beforeCompleteOrder';

    /**
     * @event OrderEvent This event is raised after an order is completed
     */
    const EVENT_AFTER_COMPLETE_ORDER = 'afterCompleteOrder';

    /**
     * @var int ID
     */
    public $id;

    /**
     * @var string Number
     */
    public $number;

    /**
     * @var string Coupon Code
     */
    public $couponCode;

    /**
     * @var string Email
     */
    public $email = 0;

    /**
     * @var bool Is completed
     */
    public $isCompleted = 0;

    /**
     * @var \DateTime Date ordered
     */
    public $dateOrdered;

    /**
     * @var \DateTime Date paid
     */
    public $datePaid;

    /**
     * @var string Currency
     */
    public $currency;

    /**
     * @var string Payment Currency
     */
    public $paymentCurrency;

    /**
     * @var int|null Gateway ID
     */
    public $gatewayId;

    /**
     * @var string Last IP
     */
    public $lastIp;

    /**
     * @var string Order locale
     */
    public $orderLocale;

    /**
     * @var string Message
     */
    public $message;

    /**
     * @var string Return URL
     */
    public $returnUrl;

    /**
     * @var string Cancel URL
     */
    public $cancelUrl;

    /**
     * @var int Order status ID
     */
    public $orderStatusId;

    /**
     * @var int Billing address ID
     */
    public $billingAddressId;

    /**
     * @var int Shipping address ID
     */
    public $shippingAddressId;

    /**
     * @var string Shipping Method Handle
     */
    public $shippingMethodHandle;

    /**
     * @var int Customer ID
     */
    public $customerId;

    /**
     * @var Address
     */
    private $_shippingAddress;

    /**
     * @var Address
     */
    private $_billingAddress;

    /**
     * @var LineItem[]
     */
    private $_lineItems;

    /**
     * @var OrderAdjustment[]
     */
    private $_orderAdjustments;

    /**
     * @var string Email
     */
    private $_email;

    /**
     * @var bool Should the order recalculate?
     */
    private $_relcalculate = true;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function beforeValidate(): bool
    {
        // Set default gateway
        if (!$this->gatewayId) {
            $gateways = Plugin::getInstance()->getGateways()->getAllFrontEndGateways();
            if (count($gateways)) {
                $this->gatewayId = $gateways[0]->id;
            }
        }

        // Get the customer ID from the session
        if (!$this->customerId && !Craft::$app->request->isConsoleRequest) {
            $this->customerId = Plugin::getInstance()->getCustomers()->getCustomerId();
        }

        $this->email = Plugin::getInstance()->getCustomers()->getCustomerById($this->customerId)->email;

        return true;
    }

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        $names = parent::datetimeAttributes();
        $names[] = 'datePaid';
        $names[] = 'dateOrdered';

        return $names;
    }

    /**
     * Updates the paid amounts on the order, and marks as complete if the order is paid.
     *
     */
    public function updateOrderPaidTotal()
    {
        if ($this->isPaid()) {
            if ($this->datePaid === null) {
                $this->datePaid = Db::prepareDateForDb(new \DateTime());
            }
        }

        // Lock for recalculation
        $originalShouldRecalculate = $this->getShouldRecalculateAdjustments();
        $this->setShouldRecalculateAdjustments(false);
        Craft::$app->getElements()->saveElement($this);

        if (!$this->isCompleted) {
            if ($this->isPaid()) {
                $this->markAsComplete();
            } else {
                // maybe not paid in full, but authorized enough to complete order.
                $totalAuthorized = Plugin::getInstance()->getPayments()->getTotalAuthorizedForOrder($this);
                if ($totalAuthorized >= $this->getTotalPrice()) {
                    $this->markAsComplete();
                }
            }
        }

        // restore recalculation lock state
        $this->setShouldRecalculateAdjustments($originalShouldRecalculate);
    }

    /**
     * @return float
     */
    public function getTotalTaxablePrice(): float
    {
        $itemTotal = $this->getItemSubtotal();

        $allNonIncludedAdjustmentsTotal = $this->getAdjustmentsTotal();
        $taxAdjustments = $this->getAdjustmentsTotalByType('tax', true);

        return $itemTotal + $allNonIncludedAdjustmentsTotal - $taxAdjustments;
    }

    /**
     * @return bool
     */
    public function getShouldRecalculateAdjustments(): bool
    {
        return $this->_relcalculate;
    }

    /**
     * @param bool $value
     */
    public function setShouldRecalculateAdjustments(bool $value)
    {
        $this->_relcalculate = $value;
    }

    /**
     * Completes an order
     *
     * @return bool
     */
    public function markAsComplete(): bool
    {
        if ($this->isCompleted) {
            return true;
        }

        $this->isCompleted = true;
        $this->dateOrdered = Db::prepareDateForDb(new \DateTime());
        $this->orderStatusId = Plugin::getInstance()->getOrderStatuses()->getDefaultOrderStatusId();

        // Raising the 'beforeCompleteOrder' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_COMPLETE_ORDER)) {
            $this->trigger(self::EVENT_BEFORE_COMPLETE_ORDER, new OrderEvent(['order' => $this]));
        }

        if (Craft::$app->getElements()->saveElement($this)) {
            // Run order complete handlers directly.
            Plugin::getInstance()->getDiscounts()->orderCompleteHandler($this);
            Plugin::getInstance()->getVariants()->orderCompleteHandler($this);
            Plugin::getInstance()->getCustomers()->orderCompleteHandler($this);

            // Raising the 'afterCompleteOrder' event
            if ($this->hasEventHandlers(self::EVENT_AFTER_COMPLETE_ORDER)) {
                $this->trigger(self::EVENT_AFTER_COMPLETE_ORDER, new OrderEvent(['order' => $this]));
            }

            return true;
        }

        Craft::error(Craft::t('commerce', 'Could not mark order {number} as complete. Order save failed during order completion with errors: {order}',
            ['number' => $this->number, 'order' => json_encode($this->errors)]), __METHOD__);

        return false;
    }

    /**
     * Removes a specific line item from the order.
     *
     * @param $lineItem
     *
     * @return void
     */
    public function removeLineItem($lineItem)
    {
        $lineItems = $this->getLineItems();
        foreach ($lineItems as $key => $item) {
            if ($lineItem->id == $item->id) {
                unset($lineItems[$key]);
                $this->setLineItems($lineItems);
            }
        }
    }

    /**
     * Add a line item to the order.
     *
     * @param $lineItem
     *
     * @return void
     */
    public function addLineItem($lineItem)
    {
        $lineItems = $this->getLineItems();
        $this->setLineItems(array_merge($lineItems, $lineItem));
    }

    /**
     * Regenerates all adjusters and update line item and order totals.
     *
     * @throws Exception
     */
    public function recalculate()
    {
        // Don't recalc the totals of completed orders.
        if (!$this->id || $this->isCompleted || !$this->getShouldRecalculateAdjustments()) {
            return;
        }

        //reset adjustments
        $this->setAdjustments([]);

        foreach ($this->getLineItems() as $item) {
            if (!$item->refreshFromPurchasable()) {
                $this->removeLineItem($item);
                // We have changed the cart contents so recalculate the order.
                $this->recalculate();

                return;
            }
        }

        // collect new adjustments
        foreach (Plugin::getInstance()->getOrderAdjustments()->getAdjusters() as $adjuster) {
            $adjustments = (new $adjuster)->adjust($this);
            $this->setAdjustments(array_merge($this->getAdjustments(), $adjustments));
        }

        // Since shipping adjusters run on the original price, pre discount, let's recalculate
        // if the currently selected shipping method is now not available after adjustments have run.
        $availableMethods = Plugin::getInstance()->getShippingMethods()->getAvailableShippingMethods($this);
        if ($this->getShippingMethodHandle()) {
            if (!isset($availableMethods[$this->getShippingMethodHandle()]) || empty($availableMethods)) {
                $this->shippingMethodHandle = null;
                $this->recalculate();

                return;
            }
        }
    }

    /**
     * @return float
     */
    public function getItemTotal(): float
    {
        $total = 0;

        foreach ($this->getLineItems() as $lineItem) {
            $total += $lineItem->getSubtotal();
        }

        return $total;
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew)
    {
        // TODO: Move the recalculate to somewhere else. Saving should be saving only
        // Right now orders always recalc when saved and not completed but that shouldn't be the case.
        $this->recalculate();

        if (!$isNew) {
            $orderRecord = OrderRecord::findOne($this->id);

            if (!$orderRecord) {
                throw new Exception('Invalid order ID: '.$this->id);
            }
        } else {
            $orderRecord = new OrderRecord();
            $orderRecord->id = $this->id;
        }

        $oldStatusId = $orderRecord->orderStatusId;

        $orderRecord->number = $this->number;
        $orderRecord->itemTotal = $this->getItemTotal();
        $orderRecord->email = $this->getEmail();
        $orderRecord->isCompleted = $this->isCompleted;
        $orderRecord->dateOrdered = $this->dateOrdered;
        $orderRecord->datePaid = $this->datePaid ?: null;
        $orderRecord->billingAddressId = $this->billingAddressId;
        $orderRecord->shippingAddressId = $this->shippingAddressId;
        $orderRecord->shippingMethodHandle = $this->shippingMethodHandle;
        $orderRecord->gatewayId = $this->gatewayId;
        $orderRecord->orderStatusId = $this->orderStatusId;
        $orderRecord->couponCode = $this->couponCode;
        $orderRecord->totalPrice = $this->getTotalPrice();
        $orderRecord->totalPaid = $this->getTotalPaid();
        $orderRecord->currency = $this->currency;
        $orderRecord->lastIp = $this->lastIp;
        $orderRecord->orderLocale = $this->orderLocale;
        $orderRecord->paymentCurrency = $this->paymentCurrency;
        $orderRecord->customerId = $this->customerId;
        $orderRecord->returnUrl = $this->returnUrl;
        $orderRecord->cancelUrl = $this->cancelUrl;
        $orderRecord->message = $this->message;

        $orderRecord->save(false);

        $previousAdjustments = OrderAdjustmentRecord::find()
            ->where(['orderId' => $this->id])
            ->all();

        $newAdjustmentIds = [];

        foreach ($this->getAdjustments() as $adjustment) {
            Plugin::getInstance()->getOrderAdjustments()->saveOrderAdjustment($adjustment);
            $newAdjustmentIds[] = $adjustment->id;
        }

        foreach ($previousAdjustments as $previousAdjustment) {
            if (!in_array($previousAdjustment->id, $newAdjustmentIds, false)) {
                $previousAdjustment->delete();
            }
        }

        //creating order history record
        $hasNewStatus = $orderRecord->id && ($oldStatusId != $orderRecord->orderStatusId);

        if ($hasNewStatus && !Plugin::getInstance()->getOrderHistories()->createOrderHistoryFromOrder($this, $oldStatusId)) {
            Craft::error('Error saving order history after Order save.', __METHOD__);
            throw new Exception('Error saving order history');
        }

        return parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     *
     * @return OrderQuery The newly created [[OrderQuery]] instance.
     */
    public static function find(): ElementQueryInterface
    {
        return new OrderQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    public static function hasContent(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isEditable(): bool
    {
        // Still a cart, allow full editing.
        if (!$this->isCompleted) {
            return true;
        }

        return Craft::$app->getUser()->checkPermission('commerce-manageOrders');
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getShortNumber();
    }

    /**
     * @return string
     */
    public function getShortNumber(): string
    {
        return substr($this->number, 0, 7);
    }

    /**
     * @inheritdoc
     * @return string
     */
    public function getLink(): string
    {
        return Template::raw("<a href='".$this->getCpEditUrl()."'>".substr($this->number, 0, 7).'</a>');
    }

    /**
     * @inheritdoc
     * @return string
     */
    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('commerce/orders/'.$this->id);
    }

    /**
     * Returns the URL to the order’s PDF invoice.
     *
     * @param string|null $option The option that should be available to the PDF template (e.g. “receipt”)
     *
     * @return string|null The URL to the order’s PDF invoice, or null if the PDF template doesn’t exist
     */
    public function getPdfUrl($option = null)
    {
        $url = null;

        // Make sure the template exists
        $template = Plugin::getInstance()->getSettings()->orderPdfPath;

        if ($template) {
            // Set Craft to the site template mode
            $templatesService = Craft::$app->getView();
            $oldTemplateMode = $templatesService->getTemplateMode();
            $templatesService->setTemplateMode(View::TEMPLATE_MODE_SITE);

            if ($templatesService->doesTemplateExist($template)) {
                $url = UrlHelper::actionUrl("commerce/downloads/pdf?number={$this->number}".($option ? "&option={$option}" : null));
            }

            // Restore the original template mode
            $templatesService->setTemplateMode($oldTemplateMode);
        }

        return $url;
    }

    /**
     * @inheritdoc
     */
    public function getFieldLayout()
    {
        /** @var OrderSettings $orderSettings */
        $orderSettings = Plugin::getInstance()->getOrderSettings()->getOrderSettingByHandle('order');

        if ($orderSettings) {
            return $orderSettings->getFieldLayout();
        }

        return null;
    }

    /**
     * Whether or not this order is made by a guest user.
     *
     * @return bool
     */
    public function isGuest(): bool
    {
        if ($this->getCustomer()) {
            return !$this->getCustomer()->userId;
        }

        return true;
    }

    /**
     * @return Customer|null
     */
    public function getCustomer()
    {
        if ($this->customerId) {
            return Plugin::getInstance()->getCustomers()->getCustomerById($this->customerId);
        }

        return null;
    }

    /**
     * Returns the email for this order. Will always be the registered users email if the order's customer is related to a user.
     *
     * @return string
     */
    public function getEmail(): string
    {
        if ($this->getCustomer() && $this->getCustomer()->getUser()) {
            $this->setEmail($this->getCustomer()->getUser()->email);
        }

        return $this->_email ?? '';
    }

    /**
     * Sets the orders email address. Will have no affect if the order's customer is a registered user.
     *
     * @param $value
     */
    public function setEmail($value)
    {
        $this->_email = $value;
    }

    /**
     * @return bool
     */
    public function isPaid(): bool
    {
        return $this->outstandingBalance() <= 0;
    }

    /**
     * @return float
     */
    public function getTotalPrice(): float
    {
        return Currency::round($this->getItemTotal() + $this->getAdjustmentsTotal());
    }

    /**
     * Returns the difference between the order amount and amount paid.
     *
     * @return float
     */
    public function outstandingBalance(): float
    {
        $totalPaid = Currency::round($this->totalPaid);
        $totalPrice = Currency::round($this->totalPrice);

        return $totalPrice - $totalPaid;
    }

    public function getTotalPaid(): float
    {
        return Plugin::getInstance()->getPayments()->getTotalPaidForOrder($this);
    }

    /**
     * @return bool
     */
    public function isUnpaid(): bool
    {
        return $this->outstandingBalance() > 0;
    }

    /**
     * Is this order the users current active cart.
     *
     * @return bool
     */
    public function isActiveCart(): bool
    {
        $cart = Plugin::getInstance()->getCart()->getCart();

        return ($cart && $cart->id == $this->id);
    }

    /**
     * Has the order got any items in it?
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->getTotalQty() == 0;
    }

    /**
     * Total number of items.
     *
     * @return int
     */
    public function getTotalQty(): int
    {
        $qty = 0;
        foreach ($this->getLineItems() as $item) {
            $qty += $item->qty;
        }

        return $qty;
    }

    /**
     * @return LineItem[]
     */
    public function getLineItems(): array
    {
        if (null === $this->_lineItems) {
            $this->setLineItems($this->id ? Plugin::getInstance()->getLineItems()->getAllLineItemsByOrderId($this->id) : []);
        }

        return $this->_lineItems;
    }

    /**
     * @param LineItem[] $lineItems
     */
    public function setLineItems(array $lineItems)
    {
        $this->_lineItems = $lineItems;
    }

    /**
     * @param string|array $type
     * @param bool         $includedOnly
     *
     *
     * @return float|int
     */
    public function getAdjustmentsTotalByType($type, $includedOnly = false)
    {
        $amount = 0;

        if (is_string($type)) {
            $type = StringHelper::split($type);
        }

        foreach ($this->getAdjustments() as $adjustment) {
            if ($adjustment->included == $includedOnly && in_array($adjustment->type, $type)) {
                $amount += $adjustment->amount;
            }
        }

        return $amount;
    }

    /**
     * @deprecated
     * @return float
     */
    public function getTotalTax(): float
    {
        Craft::$app->getDeprecator()->log('Order::getTotalTax()', 'Order::getTotalTax() has been deprecated. Use Order::getAdjustmentsTotalByType("taxIncluded") ');

        return $this->getAdjustmentsTotalByType('tax');
    }

    /**
     * @deprecated
     * @return float
     */
    public function getTotalTaxIncluded(): float
    {
        Craft::$app->getDeprecator()->log('Order::getTotalTaxIncluded()', 'Order::getTax() has been deprecated. Use Order::getAdjustmentsTotalByType("taxIncluded") ');

        return $this->getAdjustmentsTotalByType('tax', true);
    }

    /**
     * @deprecated
     *
     * @return float
     */
    public function getTotalDiscount(): float
    {
        Craft::$app->getDeprecator()->log('Order::getTotalDiscount()', 'Order::getTotalDiscount() has been deprecated. Use Order::getAdjustmentsTotalByType("discount") ');

        return $this->getAdjustmentsTotalByType('discount');
    }

    /**
     * @deprecated
     *
     * @return float
     */
    public function getTotalShippingCost(): float
    {
        Craft::$app->getDeprecator()->log('Order::getTotalDiscount()', 'Order::getTotalDiscount() has been deprecated. Use Order::getAdjustmentsTotalByType("discount") ');

        return $this->getAdjustmentsTotalByType('discount');
    }

    /**
     * @return float
     */
    public function getTotalWeight(): float
    {
        $weight = 0;
        foreach ($this->getLineItems() as $item) {
            $weight += ($item->qty * $item->weight);
        }

        return $weight;
    }

    /**
     * Returns the total sale amount.
     *
     * @return float
     */
    public function getTotalSaleAmount(): float
    {
        $value = 0;
        foreach ($this->getLineItems() as $item) {
            $value += ($item->qty * $item->saleAmount);
        }

        return $value;
    }

    /**
     * Returns the total of all line item's subtotals.
     *
     * @return float
     */
    public function getItemSubtotal(): float
    {
        $value = 0;
        foreach ($this->getLineItems() as $item) {
            $value += $item->getSubtotal();
        }

        return $value;
    }

    /**
     * Returns the total of adjustments made to order.
     *
     * @return float|int
     */
    public function getAdjustmentSubtotal(): float
    {
        $value = 0;
        foreach ($this->getAdjustments() as $adjustment) {
            if (!$adjustment->included) {
                $value += $adjustment->amount;
            }
        }

        return $value;
    }

    /**
     * @return OrderAdjustment[]
     */
    public function getAdjustments(): array
    {
        if (null === $this->_orderAdjustments) {
            $this->setAdjustments(Plugin::getInstance()->getOrderAdjustments()->getAllOrderAdjustmentsByOrderId($this->id));
        }

        return $this->_orderAdjustments;
    }

    /**
     * @return array
     */
    public function getOrderAdjustments(): array
    {
        $adjustments = $this->getAdjustments();
        $orderAdjustments = [];

        foreach ($adjustments as $adjustment) {
            if ($adjustment->lineItemId == null && $adjustment->orderId == $this->id) {
                $orderAdjustments[] = $adjustment;
            }
        }

        return $orderAdjustments;
    }

    /**
     * @param OrderAdjustment[] $adjustments
     */
    public function setAdjustments(array $adjustments)
    {
        $this->_orderAdjustments = $adjustments;
    }

    /**
     * @return float
     */
    public function getAdjustmentsTotal()
    {
        $amount = 0;

        foreach ($this->getAdjustments() as $adjustment) {
            if (!$adjustment->included) {
                $amount += $adjustment->amount;
            }
        }

        return $amount;
    }

    /**
     * @return Address|null
     */
    public function getShippingAddress()
    {
        if (null === $this->_shippingAddress) {
            $this->_shippingAddress = $this->shippingAddressId ? Plugin::getInstance()->getAddresses()->getAddressById($this->shippingAddressId) : null;
        }

        return $this->_shippingAddress;
    }

    /**
     * @param Address $address
     */
    public function setShippingAddress(Address $address)
    {
        $this->_shippingAddress = $address;
    }

    /**
     * @return Address|null
     */
    public function getBillingAddress()
    {
        if (null === $this->_billingAddress) {
            $this->_billingAddress = $this->billingAddressId ? Plugin::getInstance()->getAddresses()->getAddressById($this->billingAddressId) : null;
        }

        return $this->_billingAddress;
    }

    /**
     *
     * @param Address $address
     */
    public function setBillingAddress(Address $address)
    {
        $this->_billingAddress = $address;
    }

    /**
     * @return int|null
     */
    public function getShippingMethodId()
    {
        if ($this->getShippingMethod()) {
            return $this->getShippingMethod()->getId();
        }

        return null;
    }

    /**
     * @return ShippingMethod|null
     */
    public function getShippingMethod()
    {
        return Plugin::getInstance()->getShippingMethods()->getShippingMethodByHandle($this->getShippingMethodHandle());
    }

    /**
     * @return string|null
     */
    public function getShippingMethodHandle()
    {
        return $this->shippingMethodHandle;
    }

    /**
     * @return GatewayInterface|null
     */
    public function getGateway()
    {
        if ($this->gatewayId) {
            return Plugin::getInstance()->getGateways()->getGatewayById($this->gatewayId);
        }

        return null;
    }

    /**
     * @return OrderHistory[]
     */
    public function getHistories()
    {
        return Plugin::getInstance()->getOrderHistories()->getAllOrderHistoriesByOrderId($this->id);
    }

    /**
     * @return Transaction[]
     */
    public function getTransactions(): array
    {
        return Plugin::getInstance()->getTransactions()->getAllTransactionsByOrderId($this->id);
    }

    /**
     * Return an array of transactions for the order that have child transactions set on them.
     *
     * @return Transaction[]
     */
    public function getNestedTransactions(): array
    {
        // Transactions come in sorted by id ASC.
        // Given that transactions cannot be modified, it means that parents will always come first.
        // So we can just store a reference to them and build our tree in one pass.
        $transactions = $this->getTransactions();

        /** @var Transaction[] $referenceStore */
        $referenceStore = [];
        $nestedTransactions = [];

        foreach ($transactions as $transaction) {
            // We'll be adding all of the children in this loop, anyway, so we set the children list to an empty array.
            // This way no db queries are triggered when transactions are queried for children.
            $transaction->setChildTransactions([]);
            if ($transaction->parentId && isset($referenceStore[$transaction->parentId])) {
                $referenceStore[$transaction->parentId]->addChildTransaction($transaction);
            } else {
                $nestedTransactions[] = $transaction;
            }

            $referenceStore[$transaction->id] = $transaction;
        }

        return $nestedTransactions;
    }

    /**
     * @return OrderStatus|null
     */
    public function getOrderStatus()
    {
        return Plugin::getInstance()->getOrderStatuses()->getOrderStatusById($this->orderStatusId);
    }

    /**
     * @return null|string
     */
    public function getName()
    {
        return Craft::t('commerce', 'Orders');
    }

    /**
     * @param string $attribute
     *
     * @return mixed|string
     */
    public function getTableAttributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'orderStatus': {
                if ($this->orderStatus) {
                    return $this->orderStatus->htmlLabel();
                }

                return '<span class="status"></span>';
            }
            case 'shippingFullName': {
                if ($this->shippingAddress) {
                    return $this->shippingAddress->getFullName();
                }

                return '';
            }
            case 'billingFullName': {
                if ($this->billingAddress) {
                    return $this->billingAddress->getFullName();
                }

                return '';
            }
            case 'shippingBusinessName': {
                if ($this->shippingAddress) {
                    return $this->shippingAddress->businessName;
                }

                return '';
            }
            case 'billingBusinessName': {
                if ($this->billingAddress) {
                    return $this->billingAddress->businessName;
                }

                return '';
            }
            case 'shippingMethodName': {
                if ($this->shippingMethod) {
                    return $this->shippingMethod->getName();
                }

                return '';
            }
            case 'gatewayName': {
                if ($this->gateway) {
                    return $this->gateway->name;
                }

                return '';
            }
            case 'totalPaid':
            case 'totalPrice':
            case 'totalShippingCost':
            case 'totalDiscount': {
                if ($this->$attribute >= 0) {
                    return Craft::$app->getFormatter()->asCurrency($this->$attribute, $this->currency);
                }

                return Craft::$app->getFormatter()->asCurrency($this->$attribute * -1, $this->currency);
            }
            default: {
                return parent::tableAttributeHtml($attribute);
            }
        }
    }

    /**
     * Populate the Order.
     *
     * @param array $row
     *
     * @return Element
     */
    public function populateElementModel($row): Element
    {
        return new Order($row);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context = null): array
    {
        $sources = [
            '*' => [
                'key' => '*',
                'label' => Craft::t('commerce', 'All Orders'),
                'criteria' => ['isCompleted' => true],
                'defaultSort' => ['dateOrdered', 'desc']
            ]
        ];

        $sources[] = ['heading' => Craft::t('commerce', 'Order Status')];

        foreach (Plugin::getInstance()->getOrderStatuses()->getAllOrderStatuses() as $orderStatus) {
            $key = 'orderStatus:'.$orderStatus->handle;
            $sources[] = [
                'key' => $key,
                'status' => $orderStatus->color,
                'label' => $orderStatus->name,
                'criteria' => ['orderStatus' => $orderStatus],
                'defaultSort' => ['dateOrdered', 'desc']
            ];
        }

        $sources[] = ['heading' => Craft::t('commerce', 'Carts')];

        $edge = new \DateTime();
        $interval = new \DateInterval('PT1H');
        $interval->invert = 1;
        $edge->add($interval);

        $sources[] = [
            'key' => 'carts:active',
            'label' => Craft::t('commerce', 'Active Carts'),
            'criteria' => ['updatedAfter' => $edge, 'isCompleted' => 'not 1'],
            'defaultSort' => ['commerce_orders.dateUpdated', 'asc']
        ];

        $sources[] = [
            'key' => 'carts:inactive',
            'label' => Craft::t('commerce', 'Inactive Carts'),
            'criteria' => ['updatedBefore' => $edge, 'isCompleted' => 'not 1'],
            'defaultSort' => ['commerce_orders.dateUpdated', 'desc']
        ];

        return $sources;
    }

    /**
     * @inheritdoc
     */
    protected static function defineActions(string $source = null): array
    {
        $actions = [];

        if (Craft::$app->getUser()->checkPermission('commerce-manageOrders')) {
            $elementService = Craft::$app->getElements();
            $deleteAction = $elementService->createAction(
                [
                    'type' => Delete::class,
                    'confirmationMessage' => Craft::t('commerce', 'Are you sure you want to delete the selected orders?'),
                    'successMessage' => Craft::t('commerce', 'Orders deleted.'),
                ]
            );
            $actions[] = $deleteAction;

            // Only allow mass updating order status when all selected are of the same status, and not carts.
            $isStatus = strpos($source, 'orderStatus:');

            if ($isStatus === 0) {
                $updateOrderStatusAction = $elementService->createAction([
                    'type' => UpdateOrderStatus::class
                ]);
                $actions[] = $updateOrderStatusAction;
            }
        }

        return $actions;
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'number' => ['label' => Craft::t('commerce', 'Number')],
            'id' => ['label' => Craft::t('commerce', 'ID')],
            'orderStatus' => ['label' => Craft::t('commerce', 'Status')],
            'totalPrice' => ['label' => Craft::t('commerce', 'Total')],
            'totalPaid' => ['label' => Craft::t('commerce', 'Total Paid')],
            'totalDiscount' => ['label' => Craft::t('commerce', 'Total Discount')],
            'totalShippingCost' => ['label' => Craft::t('commerce', 'Total Shipping')],
            'dateOrdered' => ['label' => Craft::t('commerce', 'Date Ordered')],
            'datePaid' => ['label' => Craft::t('commerce', 'Date Paid')],
            'dateCreated' => ['label' => Craft::t('commerce', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('commerce', 'Date Updated')],
            'email' => ['label' => Craft::t('commerce', 'Email')],
            'shippingFullName' => ['label' => Craft::t('commerce', 'Shipping Full Name')],
            'billingFullName' => ['label' => Craft::t('commerce', 'Billing Full Name')],
            'shippingBusinessName' => ['label' => Craft::t('commerce', 'Shipping Business Name')],
            'billingBusinessName' => ['label' => Craft::t('commerce', 'Billing Business Name')],
            'shippingMethodName' => ['label' => Craft::t('commerce', 'Shipping Method')],
            'gatewayName' => ['label' => Craft::t('commerce', 'Gateway')]
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineDefaultTableAttributes(string $source = null): array
    {
        $attributes = ['number'];

        if (0 !== strpos($source, 'carts:')) {
            $attributes[] = 'orderStatus';
            $attributes[] = 'totalPrice';
            $attributes[] = 'dateOrdered';
            $attributes[] = 'totalPaid';
            $attributes[] = 'datePaid';
        } else {
            $attributes[] = 'dateUpdated';
            $attributes[] = 'totalPrice';
        }

        return $attributes;
    }

    /**
     * @inheritdoc
     */
    public function getSearchKeywords(string $attribute): string
    {
        if ($attribute === 'shortNumber') {
            return $this->getShortNumber();
        }

        if ($attribute === 'billingFirstName') {
            if ($this->getBillingAddress() && $this->getBillingAddress()->firstName) {
                return $this->billingAddress->firstName;
            }

            return '';
        }

        if ($attribute === 'billingLastName') {
            if ($this->getBillingAddress() && $this->getBillingAddress()->lastName) {
                return $this->billingAddress->firstName;
            }

            return '';
        }

        return parent::getSearchKeywords($attribute);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSearchableAttributes(): array
    {
        return [
            'number',
            'email',
            'billingFirstName',
            'billingLastName',
            'shortNumber'
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineSortOptions(): array
    {
        return [
            'number' => Craft::t('commerce', 'Number'),
            'id' => Craft::t('commerce', 'ID'),
            'orderStatusId' => Craft::t('commerce', 'Order Status'),
            'totalPrice' => Craft::t('commerce', 'Total Payable'),
            'totalPaid' => Craft::t('commerce', 'Total Paid'),
            'dateOrdered' => Craft::t('commerce', 'Date Ordered'),
            'commerce_orders.dateUpdated' => Craft::t('commerce', 'Date Updated'),
            'datePaid' => Craft::t('commerce', 'Date Paid')
        ];
    }
}
