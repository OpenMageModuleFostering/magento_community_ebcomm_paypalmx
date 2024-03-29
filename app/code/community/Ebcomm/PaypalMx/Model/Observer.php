<?php
class Ebcomm_PaypalMx_Model_Observer
{
    /**
     * Goes to reports.paypal.com and fetches Settlement reports.
     * @return Ebcomm_PaypalMx_Model_Observer
     */
    public function fetchReports()
    {
        try {
            $reports = Mage::getModel('paypalmx/report_settlement');
            /* @var $reports Ebcomm_PaypalMx_Model_Report_Settlement */
            $credentials = $reports->getSftpCredentials(true);
            foreach ($credentials as $config) {
                try {
                    $reports->fetchAndSave($config);
                } catch (Exception $e) {
                    Mage::logException($e);
                }
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Clean unfinished transaction
     *
     * @deprecated since 1.6.2.0
     * @return Ebcomm_PaypalMx_Model_Observer
     */
    public function cleanTransactions()
    {
        return $this;
    }

    /**
     * Save order into registry to use it in the overloaded controller.
     *
     * @param Varien_Event_Observer $observer
     * @return Ebcomm_PaypalMx_Model_Observer
     */
    public function saveOrderAfterSubmit(Varien_Event_Observer $observer)
    {
        /* @var $order Mage_Sales_Model_Order */
        $order = $observer->getEvent()->getData('order');
        Mage::register('hss_order', $order, true);

        return $this;
    }

    /**
     * Set data for response of frontend saveOrder action
     *
     * @param Varien_Event_Observer $observer
     * @return Ebcomm_PaypalMx_Model_Observer
     */
    public function setResponseAfterSaveOrder(Varien_Event_Observer $observer)
    {
        /* @var $order Mage_Sales_Model_Order */
        $order = Mage::registry('hss_order');

        if ($order && $order->getId()) {
            $payment = $order->getPayment();
            if ($payment && in_array($payment->getMethod(), Mage::helper('paypalmx/hss')->getHssMethods())) {
                /* @var $controller Mage_Core_Controller_Varien_Action */
                $controller = $observer->getEvent()->getData('controller_action');
                $result = Mage::helper('core')->jsonDecode(
                    $controller->getResponse()->getBody('default'),
                    Zend_Json::TYPE_ARRAY
                );

                if (empty($result['error'])) {
                    $controller->loadLayout('checkout_onepage_review');
                    $html = $controller->getLayout()->getBlock('paypal.iframe')->toHtml();
                    $result['update_section'] = array(
                        'name' => 'paypaliframe',
                        'html' => $html
                    );
                    $result['redirect'] = false;
                    $result['success'] = false;
                    $controller->getResponse()->clearHeader('Location');
                    $controller->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
                }
            }
        }

        return $this;
    }

    /**
     * Load country dependent PayPal solutions system configuration
     *
     * @param Varien_Event_Observer $observer
     * @return void
     */
    public function loadCountryDependentSolutionsConfig(Varien_Event_Observer $observer)
    {
        $requestParam = Ebcomm_PaypalMx_Block_Adminhtml_System_Config_Field_Country::REQUEST_PARAM_COUNTRY;
        $countryCode  = Mage::app()->getRequest()->getParam($requestParam);
        if (is_null($countryCode) || preg_match('/^[a-zA-Z]{2}$/', $countryCode) == 0) {
            $countryCode = (string)Mage::getSingleton('adminhtml/config_data')
                ->getConfigDataValue('paypalmx/general/merchant_country');
        }
        if (empty($countryCode)) {
            $countryCode = Mage::helper('core')->getDefaultCountry();
        }

        $paymentGroups   = $observer->getEvent()->getConfig()->getNode('sections/payment/groups');
        $paymentsConfigs = $paymentGroups->xpath('paypalmx_payments/*/backend_config/' . $countryCode);
        if ($paymentsConfigs) {
            foreach ($paymentsConfigs as $config) {
                $parent = $config->getParent()->getParent();
                $parent->extend($config, true);
            }
        }

        $payments = $paymentGroups->xpath('paypalmx_payments/*');
        foreach ($payments as $payment) {
            if ((int)$payment->include) {
                $fields = $paymentGroups->xpath((string)$payment->group . '/fields');
                if (isset($fields[0])) {
                    $fields[0]->appendChild($payment, true);
                }
            }
        }
    }
}
