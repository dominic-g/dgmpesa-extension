// Finachub M-Pesa Checkout - The waiting page is now dynamic with polling.
jQuery(document).ready(function($) {

    // Ensure dgMpesaParams is available and has necessary data
    if (typeof dgMpesaParams === 'undefined' || !dgMpesaParams.order_id) {
        return;
    }

    var intervalId;
    var orderId = dgMpesaParams.order_id;
    var ajaxUrl = dgMpesaParams.ajax_url;
    var nonce = dgMpesaParams.nonce;
    var attempts = 0;
    var maxAttempts = 120; // Check every 5 seconds for 10 minutes

    function pollStatus() {
        attempts++;

        if (attempts > maxAttempts) {
            clearInterval(intervalId);
            $('#mpesa-waiting-title').text('Payment Confirmation Timed Out');
            $('#mpesa-waiting-instruction').text('We could not get a payment confirmation within the time limit. Please check your order status in your account or contact support.');
            return;
        }

        $.ajax({
            type: 'POST',
            url: ajaxUrl,
            data: {
                action: 'dg_poll_status',
                order_id: orderId,
                nonce: nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data.redirect) {
                    clearInterval(intervalId);
                    // Redirect to the final page (Thank you or View Order)
                    window.location.href = response.data.redirect;
                } else if (response.success && response.data.status) {
                     // Still pending/on-hold - update status text
                     $('#mpesa-waiting-status-text').text('Current status: ' + response.data.status.replace(/-/g, ' '));
                } else {
                    console.log('Error polling status or invalid response:', response);
                }
            },
            error: function(xhr, status, error) {
                console.log('AJAX Error:', error);
            }
        });
    }

    // Start polling every 5 seconds
    intervalId = setInterval(pollStatus, 5000);
    
    // Run an initial check immediately
    pollStatus();
});