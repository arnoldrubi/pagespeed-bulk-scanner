jQuery(document).ready(function($) {
    $('#ps-scanner-form').on('submit', function(e) {
        e.preventDefault();

        let domain   = $('#ps-domain').val();
        let strategy = $('#ps-strategy').val();

        $('#ps-results').html('<p>Scanning... please wait.</p>');
        $('#ps-spinner-wrap').show();

        $.post(psScanner.ajaxurl, {
            action: 'ps_run_scan', // ✅ matches PHP action
            nonce: psScanner.nonce,
            domain: domain,
            strategy: strategy
        }, function(response) {
            if (response.success) {
                let results = response.data;

                let html = '<table class="ps-results-table">';
                html += '<thead><tr>' +
                    '<th>URL</th>' +
                    '<th>Performance</th>' +
                    '<th>SEO</th>' +
                    '<th>Accessibility</th>' +
                    '<th>Best Practices</th>' +
                    '<th>FCP</th>' +
                    '<th>LCP</th>' +
                    '<th>CLS</th>' +
                    '<th>TBT</th>' +
                    '</tr></thead><tbody>';

                results.forEach(function(r) {
                    if (r.error) {
                        html += '<tr>' +
                            '<td>' + r.url + '</td>' +
                            '<td colspan="5">⚠️ ' + r.error + '</td>' +
                            '</tr>';
                    } else {
                        html += '<tr>' +
                            '<td>' + r.url + '</td>' +
                            '<td>' + (r.metrics.score ?? '-') + '</td>' +
                            '<td>' + (r.metrics.SEO ?? '-') + '</td>' +
                            '<td>' + (r.metrics.Accessibility ?? '-') + '</td>' +
                            '<td>' + (r.metrics.BestPractices ?? '-') + '</td>' +
                            '<td>' + (r.metrics.FCP ?? '-') + '</td>' +
                            '<td>' + (r.metrics.LCP ?? '-') + '</td>' +
                            '<td>' + (r.metrics.CLS ?? '-') + '</td>' +
                            '<td>' + (r.metrics.TBT ?? '-') + '</td>' +
                            '</tr>';
                    }
                });

                html += '</tbody></table>';
                $('#ps-results').html(html).show();
                $('#ps-spinner-wrap').fadeOut(150);

            } else {
                // Error response from PHP (e.g., no domain, no URLs, etc.)
                let msg = response.data && response.data.message ? response.data.message : 'Unknown error occurred.';
                $('#ps-results').html('<p class="ps-error">Error: ' + msg + '</p>');
            }
        }).fail(function(xhr, status, error) {
            // Handle AJAX transport errors
            $('#ps-results').html('<p class="ps-error">AJAX request failed: ' + error + '</p>');
        });
    });
});
