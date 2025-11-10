<?php

require_once 'braintreecividesk.civix.php';

/**
 * Implementation of hook_civicrm_config().
 */
function braintreecividesk_civicrm_config(&$config) {
  _braintreecividesk_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_install().
 */
function braintreecividesk_civicrm_install() {
  return _braintreecividesk_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_enable().
 */
function braintreecividesk_civicrm_enable() {
  return _braintreecividesk_civix_civicrm_enable();
}
