(function($){
    $(document).ready(function(){
        var $form = $('#ps-scanner-form');
        var $progress = $('#ps-progress');
        var $spinner = $('#ps-spinner-wrap');
        var $progressFill = $('.ps-progress-fill');
        var $progressText = $('#ps-progress-text');
        var $resultsWrap = $('#ps-results');
        var $resultsTbody = $('#ps-results-table tbody');
        var $resultsThead = $('#ps-results-table thead tr');
        var $confirmClear = $('#ps-confirm-clear');
        var $downloadBtn = $('#ps-download-csv');
        var $scanBtn = $('#scan-button');

        var allResults = []; // accumulate scan results

        $form.on('submit', function(e){
            e.preventDefault();
            var domain = $('#ps-domain').val().trim();
            var strategy = $('#ps-strategy').val() || 'mobile';
            if (!domain) return alert('Please enter a domain or URL.');

            // reset
            $resultsTbody.empty();
            allResults = [];
            $progress.show();
            $spinner.show();
            $progressFill.css('width','0%');
            $progressText.text('Preparing...');

            // Start scan
            $.post(ps_scanner_vars.ajax_url, {
                action: 'ps_scanner_start',
                nonce: ps_scanner_vars.nonce,
                domain: domain,
                strategy: strategy
            }, function(resp){
                if (!resp || !resp.success) {
                    alert(resp && resp.data && resp.data.message ? resp.data.message : 'Unable to start scan.');
                    $spinner.fadeOut();
                    $progress.hide();
                    return;
                }
                var urls = resp.data.urls || [];
                if (!urls.length) {
                    alert('No pages found to scan.');
                    $progress.hide();
                    return;
                }

                var chunkSize = 5;
                var total = urls.length;
                var processed = 0;

                function processNextBatch(){
                    var batch = urls.splice(0, chunkSize);
                    if (!batch.length) {
                        $progressFill.css('width','100%');
                        $progressText.text('Scan complete.');
                        $downloadBtn.prop('disabled', false);
                        $spinner.fadeOut();
                        $confirmClear.show();
                        $scanBtn.prop('disabled', true);
                        return;
                    }
                    $progressText.text('Scanning ' + (processed+1) + ' - ' + Math.min(processed+batch.length, total) + ' of ' + total);
                    $.post(ps_scanner_vars.ajax_url, {
                        action: 'ps_scanner_process',
                        nonce: ps_scanner_vars.nonce,
                        urls: batch,
                        strategy: strategy
                    }, function(r2){
                        if (!r2 || !r2.success) {
                            console.warn('batch error', r2);
                        } else {
                            var results = r2.data.results || [];
                            results.forEach(function(item){
                                var url = item.url || '';
                                var res = item.result || {};

                                // Skip PageSpeed API errors
                                if (res.errors && res.errors.pagespeed_error) {
                                    console.warn('Skipping error for', url, res.errors.pagespeed_error);
                                    return; // <-- do nothing for this row
                                }

                                var $row = $('<tr></tr>');

                                if (res.error) {
                                    $row.append('<td>'+url+'</td>');
                                    $row.append('<td>'+strategy+'</td>');
                                    $row.append('<td colspan="9">⚠️ '+res.error+'</td>');
                                    $row.append('<td></td>');
                                } else {
                                    $row.append('<td>'+url+'</td>');
                                    $row.append('<td>'+strategy+'</td>');
                                    $row.append('<td>'+(res.score ?? '-')+'</td>');
                                    $row.append('<td>'+(res.SEO ?? '-')+'</td>');
                                    $row.append('<td>'+(res.Accessibility ?? '-')+'</td>');
                                    $row.append('<td>'+(res.BestPractices ?? '-')+'</td>');
                                    $row.append('<td>'+(res.FCP ?? '-')+'</td>');
                                    $row.append('<td>'+(res.LCP ?? '-')+'</td>');
                                    $row.append('<td>'+(res.CLS ?? '-')+'</td>');
                                    $row.append('<td>'+(res.TBT ?? '-')+'</td>');
                                    $row.append('<td>'+(res.SI ?? '-')+'</td>');
                                    $row.append('<td>'+(res.TTI ?? '-')+'</td>');
                                    $row.append('<td><button title="View Detailed Results" class="ps-btn ps-row-copy" data-url="https://developers.google.com/speed/pagespeed/insights/?url='+url+'"><i class="material-icons">info</i> Details</button></td>');
                                }

                                $row.hide().appendTo($resultsTbody).fadeIn(500);
                                allResults.push({ url: url, strategy: strategy, result: res });
                            });
                        }

                        processed += batch.length;
                        var pct = Math.round((processed / total) * 100);
                        $progressFill.css('width', pct + '%');

                        setTimeout(processNextBatch, 300);
                    }, 'json').fail(function(){
                        processed += batch.length;
                        setTimeout(processNextBatch, 400);
                    });
                }

                processNextBatch();
            }, 'json');
        });

        // Download CSV
        $downloadBtn.on('click', function(){
            if (!allResults.length) return alert('No results to export.');
            $downloadBtn.prop('disabled', true).text('Preparing CSV...');
            $.post(ps_scanner_vars.ajax_url, {
                action: 'ps_scanner_export',
                nonce: ps_scanner_vars.nonce,
                data: allResults
            }, function(resp){
                $downloadBtn.prop('disabled', false).text('Download CSV');
                if (!resp || !resp.success) {
                    alert(resp && resp.data && resp.data.message ? resp.data.message : 'Export failed.');
                    return;
                }
                var url = resp.data.file_url;
                if (url) {
                    window.open(url, '_blank');
                }
            }, 'json').fail(function(){
                $downloadBtn.prop('disabled', false).text('Download CSV');
            });
        });

        // action button in table (open url)
        $(document).on('click', '.ps-row-copy', function(){
            var u = $(this).data('url');
            window.open(u, '_blank');
        });

        // Clear results button
        $confirmClear.on('click', '#ps-clear-results', function(){
            if (confirm('Clear all previous results?')) {
                allResults = [];
                $resultsTbody.empty();
                $confirmClear.hide();
                $scanBtn.prop('disabled', false);
                $downloadBtn.prop('disabled', true);
            }
        });
    });
})(jQuery);
