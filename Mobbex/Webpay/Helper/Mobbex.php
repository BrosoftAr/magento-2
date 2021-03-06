<?php

namespace Mobbex\Webpay\Helper;

use Magento\Catalog\Helper\Image;
use Magento\Checkout\Model\Cart;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Mobbex
 * @package Mobbex\Webpay\Helper
 */
class Mobbex extends AbstractHelper
{
    const VERSION = '1.0.0';

    /**
     * @var ScopeConfigInterface
     */
    public $scopeConfig;

    /**
     * @var OrderInterface
     */
    public $order;

    /**
     * @var Order
     */
    public $modelOrder;

    /**
     * @var Cart
     */
    public $cart;

    /**
     * @var ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * @var LoggerInterface
     */
    protected $log;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var Image
     */
    protected $imageHelper;

    /**
     * Mobbex constructor.
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderInterface $order
     * @param Order $modelOrder
     * @param Cart $cart
     * @param ObjectManagerInterface $_objectManager
     * @param LoggerInterface $logger
     * @param UrlInterface $urlBuilder
     * @param Image $imageHelper
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        OrderInterface $order,
        Order $modelOrder,
        Cart $cart,
        ObjectManagerInterface $_objectManager,
        LoggerInterface $logger,
        UrlInterface $urlBuilder,
        Image $imageHelper
    ) {
        $this->order = $order;
        $this->modelOrder = $modelOrder;
        $this->cart = $cart;
        $this->scopeConfig = $scopeConfig;

        $this->_objectManager = $_objectManager;
        $this->log = $logger;
        $this->urlBuilder = $urlBuilder;
        $this->imageHelper = $imageHelper;
    }

    /**
     * @return bool
     */
    public function createCheckout()
    {
        $curl = curl_init();

        // get checkout object
        $checkout = $this->_objectManager->get('Magento\Checkout\Model\Type\Onepage')->getCheckout();

        // get order object
        $this->order->loadByIncrementId($checkout->getLastRealOrder()->getEntityId());

        // get extra order data
        $orderData = $this->modelOrder->load($checkout->getLastRealOrder()->getEntityId());

        // get oder id
        $orderId = $checkout->getLastRealOrderId();

        // set order description as #ORDERID
        $description = __('Orden #') . $checkout->getLastRealOrderId();

        // get order amount
        $orderAmount = round($this->order->getData('base_grand_total'), 2);

        // get customer's email
        $customerEmail = $orderData->getCustomerEmail();

        // ------------------------------

        $items = [];
        $orderedItems = $this->order->getAllVisibleItems();

        foreach ($orderedItems as $item) {
            $product = $item->getProduct();

            $items[] = [
                "image" => $this->imageHelper->init($product, 'product_small_image')->getUrl(),
                "description" => $product->getName(),
                "quantity" => $item->getQtyOrdered(),
                "total" => round($product->getPrice(), 2),
            ];
        }

        $returnUrl = $this->urlBuilder->getUrl('webpay/payment/paymentreturn', [
            '_secure' => true,
            '_current' => true,
            '_use_rewrite' => true,
            '_query' => [
                "order_id" => $orderId
            ],
        ]);

        $webhook = $this->urlBuilder->getUrl('webpay/payment/webhook', [
            '_secure' => true,
            '_current' => true,
            '_use_rewrite' => true,
            '_query' => [
                "order_id" => $orderId
            ],
        ]);

        // Create data
        $data = [
            'reference' => $orderId,
            'currency' => 'ARS',
            'email' => $customerEmail,
            'description' => $description,
            // Test Mode
            'test' => $this->scopeConfig->getValue(
                'payment/webpay/test_mode',
                ScopeInterface::SCOPE_STORE
            ),
            'return_url' => $returnUrl,
            'items' => $items,
            'webhook' => $webhook,
            "options" => [
                "theme" => $this->getTheme(),
                "platform" => $this->getPlatform(),
            ],
            'redirect' => 0,
            'total' => (float)$orderAmount,
        ];

        $headers = $this->getHeaders();

        if($this->getDebugMode())
        {
            Data::log("Checkout Headers:" . print_r($headers, true), "mobbex_debug_" . date('m_Y') . ".log");
            Data::log("Checkout Headers:" . print_r($data, true), "mobbex_debug_" . date('m_Y') . ".log");
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => "https://mobbex.com/p/checkout",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            Data::log("Checkout Error:" . print_r($err, true), "mobbex_error_" . date('m_Y') . ".log");
            return false;
        } else {
            $res = json_decode($response, true);
            Data::log("Checkout Response:" . print_r($res, true), "mobbex_" . date('m_Y') . ".log");

            return $res['data']['url'];
        }
    }

    /**
     * @param $incrementId
     * @return bool|mixed
     */
    public function getTransaction($incrementId)
    {
        $curl = curl_init();

        $data = [
            'reference' => $incrementId
        ];

        $headers = $this->getHeaders();

        if($this->getDebugMode())
        {
            Data::log("getTransaction Cron Headers:" . print_r($headers, true), "mobbex_debug_" . date('m_Y') . ".log");
            Data::log("getTransaction incrementId:" . print_r($data, true), "mobbex_debug_" . date('m_Y') . ".log");
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.mobbex.com/p/operations",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            Data::log("getTransaction Error:" . print_r($err, true), "mobbex_error_" . date('m_Y') . ".log");
            return false;
        } else {
            $res = json_decode($response, true);


            if($this->getDebugMode())
            {
                Data::log("getTransaction Response:" . print_r($res, true), "mobbex_" . date('m_Y') . ".log");
            }

            return $res;
        }
    }

    /**
     * @return array
     */
    private function getTheme()
    {
        return [
            "type" => $this->scopeConfig->getValue(
                'payment/webpay/theme',
                ScopeInterface::SCOPE_STORE
            ),
            "background" => $this->scopeConfig->getValue(
                'payment/webpay/background_color',
                ScopeInterface::SCOPE_STORE
            ),
            "colors" => [
                "primary" => $this->scopeConfig->getValue(
                    'payment/webpay/primary_color',
                    ScopeInterface::SCOPE_STORE
                ),
            ],
        ];
    }

    /**
     * @return array
     */
    private function getPlatform()
    {
        return [
            "name" => "magento_2",
            "version" => Mobbex::VERSION
        ];
    }

    /**
     * @return array
     */
    private function getHeaders()
    {
        return [
            'cache-control: no-cache',
            'content-type: application/json',
            'x-api-key: ' . $this->getApiKey(),
            'x-access-token: ' . $this->getAccessToken(),
        ];
    }

    /**
     * @return string
     */
    public function getApiKey()
    {
        return $this->scopeConfig->getValue(
            'payment/webpay/api_key',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return string
     */
    public function getAccessToken()
    {
        return $this->scopeConfig->getValue(
            'payment/webpay/access_token',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return int
     */
    public function getTestMode()
    {
        return $this->scopeConfig->getValue(
            'payment/webpay/test_mode',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return bool
     */
    public function getDebugMode()
    {
        return (bool)$this->scopeConfig->getValue(
            'payment/webpay/debug_mode',
            ScopeInterface::SCOPE_STORE
        );
    }
}
