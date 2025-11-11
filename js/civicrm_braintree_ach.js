(function($, ts) {
    // document.addEventListener('DOMContentLoaded', function() {
        // Braintree SDK Loading
        console.log('Loading Braintree SDK');
        const braintreeScript = document.createElement('script');
        braintreeScript.src = 'https://js.braintreegateway.com/web/3.94.0/js/client.min.js';
        braintreeScript.onload = function() {
            const usBankScript = document.createElement('script');
            usBankScript.src = 'https://js.braintreegateway.com/web/3.94.0/js/us-bank-account.min.js';
            // usBankScript.onload = initializeBraintreeACH;
            document.head.appendChild(usBankScript);
        };
        document.head.appendChild(braintreeScript);
        console.log('Braintree SDK loaded');
        // Braintree ACH Tokenization
        function initializeBraintreeACH(clientToken) {
            braintree.client.create({
                authorization: clientToken
            }, function (clientErr, clientInstance) {
                if (clientErr) {
                    console.error('Error creating Braintree client:', clientErr);
                    return;
                }

                braintree.usBankAccount.create({
                    client: clientInstance
                }, function (usBankAccountErr, usBankAccountInstance) {
                    if (usBankAccountErr) {
                        console.error('Error creating US Bank Account instance:', usBankAccountErr);
                        return;
                    }
                    const routingNumber = document.getElementById('bank_identification_number').value;
                    const accountNumber = document.getElementById('bank_account_number').value;
                    const accountType = document.getElementById('bank_account_type').value;
                    const ownershipType = document.getElementById('bank_ownership_type').value;
                    const firstName = document.getElementById('billing_first_name').value;
                    const lastName = document.getElementById('billing_last_name').value;
                    console.log('Tokenizing with:', {
                          routingNumber: routingNumber,
                          accountNumber: accountNumber,
                          accountType: accountType,
                          ownershipType: ownershipType,
                          firstName: firstName,
                          lastName: lastName
                      }
                    );
                    usBankAccountInstance.tokenize({
                        routingNumber: routingNumber,
                        accountNumber: accountNumber,
                        accountType: accountType,
                        ownershipType: ownershipType,
                        firstName: firstName,
                        lastName: lastName
                    }, function (tokenizeErr, payload) {
                        console.log('Tokenization result:', { tokenizeErr, payload });
                        if (tokenizeErr) {
                            console.error('Tokenization error:', tokenizeErr);
                            return;
                        }
                        document.getElementById('payment_method_nonce').value = payload.nonce;
                    });
                });
            });
        }
    //});
    console.log('Setting up form submission handler');
// on click '_qf_Main_upload' call initializeBraintreeACH with token from server
    document.getElementById('_qf_Main_upload-bottom').addEventListener('click', function(event) {
        console.log('Submit button clicked');
        event.preventDefault();
        const achForm = document.getElementById('Main');
        clientToken = document.getElementById('payment_client_token').value;
        console.log('Using client token:', clientToken);
        initializeBraintreeACH(clientToken);
        console.log('Submitting form');
        // achForm.submit();
    })
}(CRM.$, CRM.ts('com.cividesk.payment.braintreecividesk')));
