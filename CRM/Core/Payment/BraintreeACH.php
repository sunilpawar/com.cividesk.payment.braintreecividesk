<?php

use CRM_Braintreecividesk_ExtensionUtil as E;
use Civi\Payment\Exception\PaymentProcessorException;
require_once E::path('vendor/autoload.php');

class CRM_Core_Payment_BraintreeACH extends CRM_Core_Payment {
  const CHARSET = 'iso-8859-1';

  protected $_mode = NULL;
  protected $_params = [];

  protected $gateway;

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

    $config = $this->_getBraintreeConfiguration();
    $this->gateway = new Braintree\Gateway($config);
  }

  /**
   * @param \CRM_Core_Form $form
   *
   * @return bool|void
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function buildForm(&$form) {
    // Generate Client Token
    $clientTokenDetails = $this->generateClientToken();
    $clientToken = $clientTokenDetails['clientToken'];
    \Civi::resources()->addMarkup('
    <div id="braintreeachjs">
    <input type="hidden" name="payment_client_token" id="payment_client_token" value="' . $clientToken . '" />
    <input type="hidden" name="payment_method_nonce" id="payment_method_nonce" value="" />
    </div>
      ',
      ['region' => 'billing-block']
    );
    // Add Braintree ACH JS
    CRM_Core_Region::instance('billing-block')->addScriptFile('com.cividesk.payment.braintreecividesk', 'js/civicrm_braintree_ach.js');
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
      self::$_singleton[$processorName] = new CRM_Core_Payment_BraintreeACH($mode, $paymentProcessor);
    }

    return self::$_singleton[$processorName];
  }

  /**
   * Generate Client Token for ACH Tokenization
   *
   * @return string|array JSON client token
   */
  public function generateClientToken($json = FALSE): array|string {
    try {
      $config = $this->_getBraintreeConfiguration();
      $gateway = new Braintree\Gateway($config);
      $clientToken = $gateway->clientToken()->generate([
        'merchantAccountId' => $this->_paymentProcessor['user_name'] ?? NULL,
      ]);
      if ($json) {
        return json_encode([
          'clientToken' => $clientToken,
          'success' => TRUE
        ]);
      }
      return [
        'clientToken' => $clientToken,
        'success' => TRUE
      ];
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('Braintree Client Token Error: ' . $e->getMessage());
      if ($json) {
        return json_encode([
          'success' => FALSE,
          'error' => $e->getMessage()
        ]);
      }
      return [
        'error' => $e->getMessage(),
        'success' => FALSE
      ];
    }
  }

  /**
   * Do direct payment transaction
   *
   * @param array $params assoc array of input parameters for this transaction
   * @param string $component payment component (contribute/event/membership)
   *
   * @return array result of transaction
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$params, $component = 'contribute') {
    // Validate input parameters
    if (empty($params['amount']) || $params['amount'] == 0) {
      return $params;
    }


    // Prepare Braintree configuration
    $config = $this->_getBraintreeConfiguration();
    $gateway = new Braintree\Gateway($config);

    try {
      $this->_validateParams($params);

      // Prepare request array for Braintree
      $requestParams = $this->_prepareTransactionParams($params);

      // Process transaction
      $result = $gateway->transaction()->sale($requestParams);

      // Handle transaction result
      if ($result->success) {
        $params['trxn_id'] = $result->transaction->id;
        $params['gross_amount'] = $result->transaction->amount;
        $params = $this->setStatusPaymentCompleted($params);
      }
      else {
        // Throw exception with detailed error
        $errorMessages = [];
        foreach ($result->errors->deepAll() as $error) {
          $errorMessages[] = $error->message;
        }
        throw new PaymentProcessorException(implode(', ', $errorMessages));
      }
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('Braintree Payment Processing Error: ' . $e->getMessage());

      return [
        'payment_status_id' => 4, // Failed
        'error_message' => $e->getMessage()
      ];
      // throw new PaymentProcessorException($e->getMessage());
    }

    return $params;
  }

  /**
   * Validate Payment Parameters
   *
   * @param array $params payment parameters
   * @return void
   * @throws Exception for invalid parameters
   */
  private function _validateParams(array &$params): void {
    // Validate required fields
    $requiredFields = [
      'amount',
      'payment_method_nonce',
      'billing_first_name',
      'billing_last_name',
      'email'
    ];

    foreach ($requiredFields as $field) {
      if (empty($params[$field])) {
        throw new Exception("Missing required field: $field");
      }
    }

    // Additional custom validations can be added here
  }

  /**
   * Validate ACH-specific input
   */
  public function validateInput(&$params) {
    // Validate bank account details
    $errors = [];

    // Routing number validation (basic check)
    if (empty($params['bank_identification_number'])) {
      $errors['bank_identification_number'] = ts('Routing number is required');
    }
    elseif (!$this->_validateRoutingNumber($params['bank_identification_number'])) {
      $errors['bank_identification_number'] = ts('Invalid routing number');
    }

    // Account number validation
    if (empty($params['bank_account_number'])) {
      $errors['bank_account_number'] = ts('Bank account number is required');
    }
    elseif (!$this->_validateAccountNumber($params['bank_account_number'])) {
      $errors['bank_account_number'] = ts('Invalid account number');
    }

    // Account type validation
    if (empty($params['bank_account_type']) ||
      !in_array($params['bank_account_type'], ['checking', 'savings'])) {
      $errors['bank_account_type'] = ts('Please select a valid account type');
    }

    return $errors;
  }


  /**
   * Prepare transaction parameters
   *
   * @param array $params input parameters
   * @return array prepared Braintree transaction parameters
   */
  private function _prepareTransactionParams($params) {
    // Validate ACH-specific input
    /*
    $validationErrors = $this->validateInput($params);
    if (!empty($validationErrors)) {
      throw new PaymentProcessorException(implode(', ', $validationErrors));
    }
    */

    $requestParams = [
      'amount' => $params['amount'],
      'paymentMethodToken' => $this->_generateBankAccountNonce($params),
      'customerId' => $params['contactID'],
      'options' => [
        'submitForSettlement' => TRUE,
        'threeDSecure' => [
          'required' => FALSE
        ]
      ],
      /*
      'bankAccount' => [
        'accountNumber' => $params['bank_account_number'],
        'routingNumber' => $params['bank_routing_number'],
        'accountType' => $params['bank_account_type'],
        'accountHolderName' => $params['bank_account_holder_name']
          ?? ($params['first_name'] . ' ' . $params['last_name'])
      ]
      */
    ];

    // Add customer details
    $requestParams['customer'] = [
      'firstName' => $params['first_name'] ?? '',
      'lastName' => $params['last_name'] ?? '',
      'email' => $this->_getCustomerEmail($params),
    ];

    // Add billing details
    $requestParams['billing'] = $this->_getBillingDetails($params);

    // Optional: Merchant Account ID for multi-currency support
    if (!empty($this->_paymentProcessor['subject'])) {
      $requestParams['merchantAccountId'] = $this->_paymentProcessor['subject'];
    }

    return $requestParams;
  }

  /**
   * Generate bank account payment nonce
   *
   * @param array $params input parameters
   * @return string payment nonce
   */
  private function _generateBankAccountNonce($params) {
    $config = $this->_getBraintreeConfiguration();
    $gateway = new Braintree\Gateway($config);
    // Vault and verify payment method
    $paymentMethodParams = [
      'customerId' => $params['contactID'],
      'paymentMethodNonce' => $params['payment_method_nonce'],
    ];
    if (!empty($this->_paymentProcessor['merchant_account_id'])) {
      $paymentMethodParams['options'] = [
        'verificationMerchantAccountId' => $this->_paymentProcessor['merchant_account_id']
      ];
    }

    $result = $gateway->paymentMethod()->create($paymentMethodParams);

    if (!$result->success) {
      throw new \Exception("Payment method verification failed: " . $result->message);
    }
    return $result->paymentMethod->token;
  }

  /**
   * Get customer email from params
   *
   * @param array $params input parameters
   * @return string email address
   */
  private function _getCustomerEmail($params) {
    $emailFields = ['email-5', 'email-Primary', 'email'];
    foreach ($emailFields as $field) {
      if (!empty($params[$field])) {
        return $params[$field];
      }
    }
    return '';
  }

  /**
   * Prepare billing details
   *
   * @param array $params input parameters
   * @return array billing details
   */
  private function _getBillingDetails($params) {
    $billingDetails = [
      'firstName' => $params['billing_first_name'] ?? $params['first_name'] ?? '',
      'lastName' => $params['billing_last_name'] ?? $params['last_name'] ?? '',
    ];

    // Add address details if available
    $addressFields = [
      'streetAddress' => 'billing_street_address-5',
      'locality' => 'billing_city-5',
      'region' => 'billing_state_province_id-5',
      'postalCode' => 'billing_postal_code-5',
    ];

    foreach ($addressFields as $braintreeKey => $crmKey) {
      if (!empty($params[$crmKey])) {
        // For state/province, convert to abbreviation
        if ($braintreeKey === 'region') {
          $billingDetails[$braintreeKey] = CRM_Core_PseudoConstant::stateProvinceAbbreviation($params[$crmKey], FALSE);
        }
        else {
          $billingDetails[$braintreeKey] = $params[$crmKey];
        }
      }
    }

    // Add country code
    if (!empty($params['billing_country_id-5'])) {
      $billingDetails['countryCodeAlpha2'] = CRM_Core_PseudoConstant::countryIsoCode($params['billing_country_id-5']);
    }

    return $billingDetails;
  }

  /**
   * Create Braintree gateway configuration
   *
   * @return Braintree\Configuration
   */
  private function _getBraintreeConfiguration() {
    $environment = ($this->_mode == "test" ? 'sandbox' : 'production');
    return new Braintree\Configuration([
      'environment' => $environment,
      'merchantId' => $this->_paymentProcessor["user_name"],
      'publicKey' => $this->_paymentProcessor["password"],
      'privateKey' => $this->_paymentProcessor["signature"]
    ]);
  }

  /**
   * Check payment processor configuration
   *
   * @return string|null error message or null if configuration is valid
   */
  public function checkConfig() {
    $error = [];

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('Merchant ID is not set');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('Public Key is not set');
    }

    if (empty($this->_paymentProcessor['signature'])) {
      $error[] = ts('Private Key is not set');
    }

    return !empty($error) ? implode('<br/>', $error) : NULL;
  }

  /**
   * Validate routing number using basic checks
   *
   * @param string $routingNumber
   * @return bool
   */
  private function _validateRoutingNumber($routingNumber) {
    // Basic validation - check length and format
    if (!preg_match('/^\d{9}$/', $routingNumber)) {
      return FALSE;
    }

    // Optional: Implement ABA routing number checksum algorithm
    $checksum = 0;
    for ($i = 0; $i < 9; $i += 3) {
      $checksum +=
        (int)$routingNumber[$i] * 3 +
        (int)$routingNumber[$i + 1] * 7 +
        (int)$routingNumber[$i + 2];
    }

    return ($checksum % 10 == 0);
  }

  /**
   * Basic account number validation
   *
   * @param string $accountNumber
   * @return bool
   */
  private function _validateAccountNumber($accountNumber) {
    // Basic validation - check length and format
    return (
      !empty($accountNumber) &&
      preg_match('/^\d{4,17}$/', $accountNumber)
    );
  }

  // CiviCRM Endpoint for Client Token Generation
  public static function braintree_clienttoken() {
    // Verify request is authorized
    if (!CRM_Core_Permission::check('make online contributions')) {
      http_response_code(403);
      echo json_encode(['error' => 'Unauthorized']);
      CRM_Utils_System::civiExit();
    }

    // Get active Braintree ACH payment processor
    $paymentProcessor = civicrm_api3('PaymentProcessor', 'get', [
      'payment_processor_type' => 'Braintree_ACH',
      'is_active' => 1,
      'options' => ['limit' => 1]
    ])['values'][0];

    $processor = new CRM_Core_Payment_BraintreeACH(
      $paymentProcessor['is_test'] ? 'test' : 'live',
      $paymentProcessor
    );

    // Output client token
    header('Content-Type: application/json');
    echo $processor->generateClientToken(TRUE);
    CRM_Utils_System::civiExit();
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    return [
      'account_holder' => [
        'htmlType' => 'text',
        'name' => 'account_holder',
        'title' => E::ts('Name on Account'),
        'description' => E::ts('The name of the person or business that holds the bank account'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 22,
          'autocomplete' => 'on',
        ],
        'is_required' => TRUE,
      ],
      // US account number (max 17 digits)
      'bank_account_number' => [
        'htmlType' => 'text',
        'name' => 'bank_account_number',
        'title' => E::ts('Account Number'),
        'description' => E::ts('Usually between 8 and 12 digits - identifies your individual account'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 17,
          'autocomplete' => 'off',
        ],
        'rules' => [
          [
            'rule_message' => E::ts('Please enter a valid Bank Identification Number (value must not contain punctuation characters).'),
            'rule_name' => 'nopunctuation',
            'rule_parameters' => NULL,
          ],
        ],
        'is_required' => TRUE,
      ],
      'bank_identification_number' => [
        'htmlType' => 'text',
        'name' => 'bank_identification_number',
        'title' => E::ts('Routing Number'),
        'description' => E::ts('A 9-digit code (ABA number) that is used to identify where your bank account was opened (eg. 211287748)'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 9,
          'autocomplete' => 'off',
        ],
        'is_required' => TRUE,
        'rules' => [
          [
            'rule_message' => E::ts('Please enter a valid Bank Identification Number (value must not contain punctuation characters).'),
            'rule_name' => 'nopunctuation',
            'rule_parameters' => NULL,
          ],
        ],
      ],
      'bank_name' => [
        'htmlType' => 'text',
        'name' => 'bank_name',
        'title' => E::ts('Bank Name'),
        'description' => E::ts('The name of your bank or financial institution'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 50,
          'autocomplete' => 'off',
        ],
        'is_required' => TRUE,
      ],
      'bank_account_type' => [
        'htmlType' => 'select',
        'name' => 'bank_account_type',
        'title' => E::ts('Account Type'),
        'description' => E::ts('Indicates whether the bank account is a checking or savings account'),
        'attributes' => [
          'checking' => E::ts('Checking'),
          'savings' => E::ts('Savings'),
        ],
        'is_required' => TRUE,
      ],
      'bank_ownership_type' => [
        'htmlType' => 'select',
        'name' => 'bank_ownership_type',
        'title' => E::ts('Ownership Type'),
        'description' => E::ts('Indicates whether the bank account is a personal or business account'),
        'attributes' => [
          'personal' => E::ts('Personal'),
          'business' => E::ts('Business'),
        ],
        'is_required' => TRUE,
      ],

    ];
  }

  /**
   * Get array of fields that should be displayed on the payment form for direct debits.
   *
   * @return array
   */
  protected function getDirectDebitFormFields() {
    return [
      'account_holder',
      'bank_account_number',
      'bank_identification_number',
      'bank_name',
      'bank_account_type',
      'bank_ownership_type',
    ];
  }

}


