<?php
use CRM_Braintreecividesk_ExtensionUtil as E;
use Civi\Payment\Exception\PaymentProcessorException;
require_once E::path('vendor/autoload.php');

class CRM_Core_Payment_Braintreecividesk extends CRM_Core_Payment {
  const CHARSET = 'iso-8859-1';

  protected $_mode = NULL;
  protected $_params = [];

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE) {
    $processorName = $paymentProcessor['name'];

    if (CRM_Utils_Array::value($processorName, self::$_singleton) === NULL) {
      self::$_singleton[$processorName] = new CRM_Core_Payment_Braintreecividesk($mode, $paymentProcessor);
    }

    return self::$_singleton[$processorName];
  }


  function doTransferCheckout(&$params, $component = 'contribute') {
    CRM_Core_Error::fatal(ts('Use direct billing instead of Transfer method.'));
  }

  /**
   * Submit a payment using Advanced Integration Method.
   *
   * @param array|\Civi\Payment\PropertyBag $params
   *
   * @param string $component
   *
   * @return array
   *   Result array (containing at least the key payment_status_id)
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$params, $component = 'contribute') {

    // Let a $0 transaction pass
    if (empty($params['amount']) || $params['amount'] == 0) {
      return $params;
    }

    // Get proper entry URL for returning on error.
    $requestArray = $this->formRequestArray($params);
    $environment = ($this->_mode == "test" ? 'sandbox' : 'production');
    $config = new Braintree\Configuration([
      'environment' => $environment,
      'merchantId' => $this->_paymentProcessor["user_name"],
      'publicKey' => $this->_paymentProcessor["password"],
      'privateKey' => $this->_paymentProcessor["signature"]
    ]);

    $gateway = new Braintree\Gateway($config);
    try {
      $result = $gateway->transaction()->sale($requestArray);
    }
    catch (Exception $e) {
      throw new PaymentProcessorException(CRM_Core_Error::getMessages($result));
    }

    if ($result->success) {
      $params['trxn_id'] = $result->transaction->id;
      $params['gross_amount'] = $result->transaction->amount;
      $params = $this->setStatusPaymentCompleted($params);
    }
    else {
      if ($result->transaction) {
        throw new PaymentProcessorException($result->message, $result->transaction->processorResponseCode);
      }
      else {
        $error = "Validation errors:<br/>";
        foreach ($result->errors->deepAll() as $e) {
          $error .= $e->message;
        }
        throw new PaymentProcessorException($error, 9001);
      }
    }

    return $params;
  }

  function &error($errorCode = NULL, $errorMessage = NULL) {
    $e = CRM_Core_Error::singleton();

    if ($errorCode) {
      $e->push($errorCode, 0, NULL, $errorMessage);
    }
    else {
      $e->push(9001, 0, NULL, 'Unknown System Error.');
    }
    return $e;
  }

  /**
   * This function checks to see if we have the right config values
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig() {
    $error = [];

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('Merchant Id is not set for this payment processor');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('Public Key is not set for this payment processor');
    }

    if (empty($this->_paymentProcessor['signature'])) {
      $error[] = ts('Private Key is not set for this payment processor');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /*
   *   This function returns the request array
   *   @param  array $params assoc array of input parameters for this transaction
   *   @return Array
   */
  function formRequestArray($postArray) {
    $requestArray = [
      'amount' => $postArray['amount'],
      'creditCard' => [
        'number' => $postArray['credit_card_number'],
        'expirationMonth' => $postArray['credit_card_exp_date']['M'],
        'expirationYear' => $postArray['credit_card_exp_date']['Y'],
        'cvv' => $postArray['cvv2']
      ],
      'options' => [
        'submitForSettlement' => TRUE,
      ],
    ];

    // Allow usage of a merchant account ID (for charging in other currencies) - see README.md
    if (!empty($this->_paymentProcessor['subject'])) {
      $requestArray['merchantAccountId'] = $this->_paymentProcessor['subject'];
    }

    if (array_key_exists('first_name', $postArray)) {
      $requestArray['customer'] = [
        'firstName' => $postArray['first_name'],
        'lastName' => $postArray['last_name']
      ];

      // Different versions of CiviCRM have different field names for the email address
      // Fields are ordered in order of preference ; email-5 is Billing
      $fields = ['email-5', 'email-Primary'];
      foreach (array_reverse($fields) as $field) {
        if (!empty($postArray[$field])) {
          $requestArray['customer']['email'] = $postArray[$field];
        }
      }
    }

    if (array_key_exists('billing_first_name', $postArray)) {
      $requestArray['billing'] = [
        'firstName' => $postArray['billing_first_name'],
        'lastName' => $postArray['billing_last_name'],
        'streetAddress' => $postArray['billing_street_address-5'],
        'locality' => $postArray['billing_city-5'],
        'region' => CRM_Core_PseudoConstant::stateProvinceAbbreviation($postArray['billing_state_province_id-5'], FALSE),
        'postalCode' => $postArray['billing_postal_code-5'],
        // 'countryCodeAlpha2' => $postArray['billing_country_id-5']
        'countryCodeAlpha2' => CRM_Core_PseudoConstant::countryIsoCode($postArray['billing_country_id-5'])
      ];
    }

    return $requestArray;
  }

}
