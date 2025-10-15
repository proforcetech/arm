(function($){
    'use strict';

    const settings = window.ARM_RE_TIME || {};
    const notices  = $('#arm-tech-time__notice');

    function showNotice(type, message) {
        if (!notices.length) {
            return;
        }
        notices.removeClass('notice-error notice-success notice-info').addClass('notice-' + type);
        notices.html('<p>' + message + '</p>').show();
        if (type === 'success') {
            setTimeout(function(){ notices.fadeOut(); }, 4000);
        }
    }

    function formatHtml(text) {
        return $('<div>').text(text).html();
    }

    function updateRow($row, payload, successMessage) {
        if (!payload || !$row.length) {
            return;
        }

        const totals = payload.totals || {};
        const open   = totals.open_entry || null;
        const entry  = payload.entry || null;

        const $totalCell = $row.find('.arm-tech-time__total');
        if ($totalCell.length) {
            const formatted = totals.formatted || '0:00';
            $totalCell.attr('data-total-minutes', totals.minutes || 0);
            $totalCell.find('strong').text(formatted);
        }

        const $startButton = $row.find('.arm-time-start');
        const $stopButton  = $row.find('.arm-time-stop');
        const $statusCell  = $row.find('.arm-tech-time__running');

        if (open) {
            $startButton.prop('disabled', true);
            $stopButton.prop('disabled', false);
            if (entry && entry.id) {
                $stopButton.attr('data-entry', entry.id);
            } else if (open.id) {
                $stopButton.attr('data-entry', open.id);
            }

            const startLabel = open.start_at || (entry ? entry.start_at : '');
            const text = settings.i18n && settings.i18n.runningSince ? settings.i18n.runningSince.replace('%s', startLabel) : 'Running since ' + startLabel;

            if ($statusCell.length) {
                $statusCell.attr('data-entry-id', open.id || '').text(text).show();
            } else {
                $row.find('td').eq(3).append(
                    $('<span class="description arm-tech-time__running">').attr('data-entry-id', open.id || '').text(text)
                );
            }
        } else {
            $startButton.prop('disabled', false);
            $stopButton.prop('disabled', true).attr('data-entry', '');
            if ($statusCell.length) {
                $statusCell.hide();
            }
        }

        if (successMessage) {
            showNotice('success', formatHtml(successMessage));
        }
    }

    function request(url, data, onSuccess, onError) {
        if (!url) {
            return;
        }
        const payload = Object.assign({}, data);
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': settings.nonce || ''
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function(response){
            if (!response.ok) {
                return response.json().then(function(body){ throw body; });
            }
            return response.json();
        }).then(function(json){
            if (typeof onSuccess === 'function') {
                onSuccess(json);
            }
        }).catch(function(error){
            if (typeof onError === 'function') {
                onError(error);
            }
        });
    }

    $(document).on('click', '.arm-time-start', function(){
        const $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        const jobId = parseInt($btn.data('job'), 10);
        if (!jobId) {
            return;
        }
        const $row = $btn.closest('tr');
        $btn.prop('disabled', true);
        request(settings.rest ? settings.rest.start : '', { job_id: jobId }, function(response){
            updateRow($row, response, settings.i18n ? settings.i18n.started : 'Timer started.');
        }, function(error){
            $btn.prop('disabled', false);
            const msg = error && error.message ? error.message : (settings.i18n ? settings.i18n.startError : 'Unable to start timer.');
            showNotice('error', formatHtml(msg));
        });
    });

    $(document).on('click', '.arm-time-stop', function(){
        const $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        const jobId   = parseInt($btn.data('job'), 10);
        const entryId = parseInt($btn.data('entry'), 10);
        if (!jobId && !entryId) {
            return;
        }
        const $row = $btn.closest('tr');
        $btn.prop('disabled', true);
        request(settings.rest ? settings.rest.stop : '', { job_id: jobId || undefined, entry_id: entryId || undefined }, function(response){
            updateRow($row, response, settings.i18n ? settings.i18n.stopped : 'Timer stopped.');
        }, function(error){
            $btn.prop('disabled', false);
            const msg = error && error.message ? error.message : (settings.i18n ? settings.i18n.stopError : 'Unable to stop timer.');
            showNotice('error', formatHtml(msg));
        });
    });
})(jQuery);
