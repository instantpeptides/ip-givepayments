jQuery(document).ready(function ($) {
    const pluginId = givepayments_admin_params.plugin_id;

    function updateApiKeyAndMerchantId() {
        var environment = $('#woocommerce_' + pluginId + '_environment').val();
        var apiKeyField = $('#woocommerce_' + pluginId + '_api_key');
        var merchantIdField = $('#woocommerce_' + pluginId + '_merchant_id');
        var apiKey = (environment === 'production')
            ? givepayments_admin_params.production_api_key
            : givepayments_admin_params.sandbox_api_key;
        var merchantId = givepayments_admin_params.merchant_id;
        apiKeyField.val(apiKey);
        merchantIdField.val(merchantId);
    }

    function toggleApiKeyField() {
        var environment = $('#woocommerce_' + pluginId + '_environment').val();
        var apiKeyField = $('#woocommerce_' + pluginId + '_api_key');
        var apiKeyLabel = $('label[for="woocommerce_' + pluginId + '_api_key"]');
        var apiKeyTitle = apiKeyField.closest('tr').find('th strong');
        var apiKeyDescription = apiKeyField.siblings('.description');
        const connectionTestButton = $('#woocommerce_' + pluginId + '_connection');

        if (connectionTestButton.val().trim() === '') {
            connectionTestButton.val(givepayments_admin_params.i18n.connection_test);
        }

        var $reregisterBtn = $('#woocommerce_' + pluginId + '_reregister_webhook');
        if ($reregisterBtn.val().trim() === '') {
            $reregisterBtn.val(givepayments_admin_params.i18n.reregister_webhook || 'Re-register Webhook');
        }

        if (environment === 'production') {
            apiKeyLabel.text(givepayments_admin_params.i18n.prod_api_key_label);
            apiKeyTitle.text(givepayments_admin_params.i18n.prod_api_key_label);
            apiKeyDescription.text(givepayments_admin_params.i18n.prod_api_key_desc);
            $("#able-to-process-text").show();
            $("#able-to-transfer-text").show();
        } else {
            apiKeyLabel.text(givepayments_admin_params.i18n.sandbox_api_key_label);
            apiKeyTitle.text(givepayments_admin_params.i18n.sandbox_api_key_label);
            apiKeyDescription.text(givepayments_admin_params.i18n.sandbox_api_key_desc);
            $("#able-to-process-text").hide();
            $("#able-to-transfer-text").hide();
        }

        // Force UI update
        apiKeyField.closest('tr').hide().show(0);
    }

    // Run on page load
    toggleApiKeyField();
    updateApiKeyAndMerchantId();

    // Bind event listener to dropdown change
    $('#woocommerce_' + pluginId + '_environment').on('change', function () {
        toggleApiKeyField();
        updateApiKeyAndMerchantId();
    });

    // Also trigger when settings are dynamically changed
    $(document).on('change', '#woocommerce_' + pluginId + '_environment', function () {
        toggleApiKeyField();
        updateApiKeyAndMerchantId();
    });

    // Handle connection test button click
    $('#woocommerce_' + pluginId + '_connection').on('click', function (e) {
        e.preventDefault();

        // Show loading spinner or disable button
        $(this).prop('disabled', true).text(givepayments_admin_params.i18n.testing);

        $.ajax({
            url: givepayments_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'test_connection',
                nonce: givepayments_admin_params.nonce,
                api_key: $('#woocommerce_' + pluginId + '_api_key').val(),
                merchant_id: $('#woocommerce_' + pluginId + '_merchant_id').val(),
                environment: $('#woocommerce_' + pluginId + '_environment').val()
            },
            success: function (response) {
                const enabledObj = {
                    status: "Enabled",
                    connectionState: "Successful",
                    className: "connection-span-success"
                };
                const disabledObj = {
                    status: "Disabled",
                    connectionState: "Failed",
                    className: "connection-span-declined"
                };

                let canTransferMoney = disabledObj;
                let canProcessMoney = disabledObj;
                let connectionStatus = disabledObj;

                if (response.success) {
                    canTransferMoney = response.data.canTransferMoney ? enabledObj : disabledObj;
                    canProcessMoney = response.data.canProcessMoney ? enabledObj : disabledObj;
                    connectionStatus = enabledObj;
                }

                $("#able-to-process-text span").text(canProcessMoney.status)
                    .removeClass().addClass(canProcessMoney.className);
                $("#able-to-transfer-text span").text(canTransferMoney.status)
                    .removeClass().addClass(canTransferMoney.className);
                $("#connection-status-text span").text(connectionStatus.connectionState)
                    .removeClass().addClass(connectionStatus.className);
                $(".connection-limit-row-container").css("display", "flex");
                $('#woocommerce_' + pluginId + '_connection').prop('disabled', false)
                    .text(givepayments_admin_params.i18n.connection_test);
            },
            error: function (error) {
                if (error.responseJSON && error.responseJSON.message) {
                    console.log(error.responseJSON.message);
                }
                alert(givepayments_admin_params.i18n.error_message);
                $('#woocommerce_' + pluginId + '_connection').prop('disabled', false)
                    .text(givepayments_admin_params.i18n.connection_test);
            }
        });
    });

    // Re-register Webhook button.
    // Two-step flow: (1) AJAX call to clear the env-scoped webhook options
    // server-side, (2) programmatic click of the existing Connection Test
    // button so the registration code path runs and persists a fresh secret.
    // We reuse the Connection Test path on purpose, it already handles the
    // full registration handshake, so there is no duplicate logic to maintain.
    var i18n = givepayments_admin_params.i18n || {};
    $('#woocommerce_' + pluginId + '_reregister_webhook').on('click', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var $status = $('#givepayments-reregister-status');
        var originalLabel = $btn.val() || i18n.reregister_webhook || 'Re-register Webhook';

        $btn.prop('disabled', true).val(i18n.reregistering || 'Clearing stored secret…');
        $status.text('').css('color', '');

        $.ajax({
            url: givepayments_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'givepayments_reregister_webhook',
                nonce: givepayments_admin_params.nonce,
                environment: $('#woocommerce_' + pluginId + '_environment').val()
            },
            success: function (response) {
                if (!response || !response.success) {
                    $status.text(i18n.reregister_failed || 'Re-registration failed. Check WooCommerce logs (source: givepayments).').css('color', '#b32d2e');
                    $btn.prop('disabled', false).val(originalLabel);
                    return;
                }

                // Step 2: trigger the existing Connection Test handler. Because
                // we just deleted givepayments_webhook_set_<env> et al., the
                // server-side `$needs_registration` check will be true and a
                // brand-new webhook will be registered with a fresh secret.
                $status.text(i18n.reregister_then_test || 'Cleared. Re-running Test Connection…').css('color', '');
                $('#woocommerce_' + pluginId + '_connection').trigger('click');

                // Re-enable our button shortly after, the Connection Test
                // button manages its own disabled state.
                setTimeout(function () {
                    $btn.prop('disabled', false).val(originalLabel);
                    $status.text(i18n.reregister_done || 'Webhook re-registered successfully.').css('color', '#1a7f37');
                }, 1500);
            },
            error: function () {
                $status.text(i18n.reregister_failed || 'AJAX error. Check WooCommerce logs (source: givepayments).').css('color', '#b32d2e');
                $btn.prop('disabled', false).val(originalLabel);
            }
        });
    });
});