<?php

return [
  [
    'module' => 'com.cividesk.payment.braintreecividesk',
    'name' => 'braintreecividesk',
    'entity' => 'PaymentProcessorType',
    'params' => [
      'version' => 3,
      'name' => 'braintreecividesk',
      'title' => 'Braintree cividesk',
      'description' => 'Braintree Payment Processor',
      'class_name' => 'Payment_Braintreecividesk',
      'billing_mode' => 1,
      'user_name_label' => 'Merchant Id',
      'password_label' => 'Public Key',
      'signature_label' => 'Private Key',
      'url_site_default'=> NULL,
      'url_recur_default' => NULL,
      'url_site_test_default' => NULL,
      'url_recur_test_default' => NULL,
      'is_recur' => 1,
      'payment_type' => 1
    ],
  ],
  [
    'module' => 'com.cividesk.payment.braintreecividesk',
    'name' => 'braintreeach',
    'entity' => 'PaymentProcessorType',
    'params' => [
      'version' => 3,
      'name' => 'braintreeach',
      'title' => 'Braintree ACH',
      'description' => 'Braintree ACH Payment Processor',
      'class_name' => 'Payment_BraintreeACH',
      'billing_mode' => 1,
      'user_name_label' => 'Merchant Id',
      'password_label' => 'Public Key',
      'signature_label' => 'Private Key',
      'url_site_default' => NULL,
      'url_recur_default' => NULL,
      'url_site_test_default' => NULL,
      'url_recur_test_default' => NULL,
      'is_recur' => 1,
      'payment_type' => 2  // ACH/Bank Account type
    ],
  ]
];