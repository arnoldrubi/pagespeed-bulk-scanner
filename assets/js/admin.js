jQuery(document).ready(function($) {
    const $apiInput = $('input[name="ps_scanner_options[api_key]"]');
    const $notice = $('<div class="ps-api-notice" style="margin-top:5px;"></div>');
    $apiInput.after($notice);

    let timer = null;

    $apiInput.on('input', function() {
        clearTimeout(timer);
        const key = $(this).val().trim();

        if (!key) {
            $notice.text('').removeClass('updated error');
            return;
        }

        $notice.text('Checking API key...').removeClass('error updated');

        timer = setTimeout(function() {
            $.post(PSAdmin.ajax_url, {
                action: 'ps_validate_api_key',
                api_key: key,
                _ajax_nonce: PSAdmin.nonce
            })
            .done(function(res) {
                if (res.success) {
                    $notice
                        .text(res.data.message)
                        .removeClass('error')
                        .addClass('updated');
                } else {
                    $notice
                        .text('❌ ' + res.data.message)
                        .removeClass('updated')
                        .addClass('error');
                }
            })
            .fail(function() {
                $notice.text('Error contacting server.').addClass('error');
            });
        }, 800); // debounce 0.8s
    });

    console.log('Admin script loaded.');
});
