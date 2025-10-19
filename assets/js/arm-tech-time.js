(function($){
    'use strict';

    const settings = window.ARM_RE_TIME || {};
    const notices  = $('#arm-tech-time__notice');
    const summaryWrap = $('#arm-tech-time__summary');
    let activeJobId = parseInt(settings.activeJobId || 0, 10) || 0;
    let locationWarningShown = false;

    function showNotice(type, message) {
        if (!notices.length) {
            return;
        }
        const allowed = ['error', 'success', 'info', 'warning'];
        if (allowed.indexOf(type) === -1) {
            type = 'info';
        }
        notices
            .removeClass('notice-error notice-success notice-info notice-warning')
            .addClass('notice-' + type)
            .html('<p>' + message + '</p>')
            .show();
        if (type === 'success') {
            setTimeout(function(){ notices.fadeOut(); }, 4000);
        }
    }

    function formatHtml(text) {
        return $('<div>').text(text).html();
    }

    function updateSummary(summary) {
        if (!summaryWrap.length || !summary) {
            return;
        }
        if (summary.work_formatted !== undefined) {
            summaryWrap.find('[data-summary="work"] [data-summary-field="value"]').text(summary.work_formatted);
        }
        if (summary.work_decimal_formatted !== undefined) {
            const template = settings.i18n && settings.i18n.decimalLabel ? settings.i18n.decimalLabel : 'Decimal: %s hrs';
            summaryWrap.find('[data-summary="work"] [data-summary-field="decimal"]').text(template.replace('%s', summary.work_decimal_formatted));
        }
        if (summary.billable_formatted !== undefined) {
            summaryWrap.find('[data-summary="billable"] [data-summary-field="value"]').text(summary.billable_formatted);
        }
        if (summary.assigned_count !== undefined) {
            summaryWrap.find('[data-summary="assigned"] [data-summary-field="value"]').text(summary.assigned_count);
        }
        if (summary.completed_count !== undefined) {
            summaryWrap.find('[data-summary="completed"] [data-summary-field="value"]').text(summary.completed_count);
        }
    }

    function findActiveJobId() {
        let id = 0;
        const $running = $('.arm-tech-time__running:visible').first();
        if ($running.length) {
            id = parseInt($running.closest('tr').data('job-id'), 10) || 0;
        }
        return id;
    }

    function setActiveJob(jobId) {
        activeJobId = jobId || 0;
        const $table = $('.arm-tech-time__table').first();
        if ($table.length) {
            $table.attr('data-active-job', activeJobId || '');
        }
        $('.arm-time-start').each(function(){
            const $btn = $(this);
            const rowJobId = parseInt($btn.data('job'), 10);
            const rowHasOpen = $btn.closest('tr').find('.arm-tech-time__running:visible').length > 0;
            if (activeJobId && rowJobId !== activeJobId) {
                $btn.prop('disabled', true);
            } else if (rowHasOpen) {
                $btn.prop('disabled', true);
            } else if (!activeJobId) {
                $btn.prop('disabled', false);
            }
        });
    }

    function updateRow($row, payload, successMessage, jobId) {
        if (!$row || !$row.length || !payload) {
            return;
        }

        const totals = payload.totals || {};
        const open   = totals.open_entry || null;
        const entry  = payload.entry || null;
        const summary = payload.summary || null;

        const $totalCell = $row.find('.arm-tech-time__total');
        if ($totalCell.length) {
            const formatted = totals.formatted || '0:00';
            $totalCell.attr('data-total-minutes', totals.minutes || 0);
            $totalCell.find('strong').text(formatted);
        }

        const $startButton = $row.find('.arm-time-start');
        const $stopButton  = $row.find('.arm-time-stop');
        const $statusCell  = $row.find('.arm-tech-time__running');

        if (typeof jobId !== 'number' || isNaN(jobId)) {
            jobId = parseInt($row.data('job-id'), 10) || 0;
        }

        if (open) {
            $startButton.prop('disabled', true);
            $stopButton.prop('disabled', false);
            if (entry && entry.id) {
                $stopButton.attr('data-entry', entry.id);
            } else if (open.id) {
                $stopButton.attr('data-entry', open.id);
            }
            const startLabel = open.start_at || (entry ? entry.start_at : '');
            const text = settings.i18n && settings.i18n.runningSince
                ? settings.i18n.runningSince.replace('%s', startLabel)
                : 'Running since ' + startLabel;

            if ($statusCell.length) {
                $statusCell.attr('data-entry-id', open.id || '').text(text).show();
            } else {
                $row.find('td').eq(3).append(
                    $('<span class="description arm-tech-time__running">').attr('data-entry-id', open.id || '').text(text)
                );
            }
            setActiveJob(jobId);
        } else {
            $startButton.prop('disabled', false);
            $stopButton.prop('disabled', true).attr('data-entry', '');
            if ($statusCell.length) {
                $statusCell.hide();
            }
            if (activeJobId === jobId) {
                setActiveJob(findActiveJobId());
            } else {
                setActiveJob(activeJobId || findActiveJobId());
            }
        }

        if (summary) {
            updateSummary(summary);
        }

        if (successMessage) {
            showNotice('success', formatHtml(successMessage));
        }
    }

    function request(url, data, onSuccess, onError, onSettled) {
        if (!url) {
            if (typeof onError === 'function') {
                onError({ message: 'Request URL missing.' });
            }
            if (typeof onSettled === 'function') {
                onSettled();
            }
            return;
        }

        const payload = Object.assign({}, data || {});
        Object.keys(payload).forEach(function(key){
            if (payload[key] === undefined) {
                delete payload[key];
            }
        });

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
        }).finally(function(){
            if (typeof onSettled === 'function') {
                onSettled();
            }
        });
    }

    function captureLocation() {
        return new Promise(function(resolve){
            if (!navigator.geolocation) {
                resolve(null);
                return;
            }

            navigator.geolocation.getCurrentPosition(
                function(position){
                    const coords = position.coords || {};
                    resolve({
                        latitude: typeof coords.latitude === 'number' ? coords.latitude : null,
                        longitude: typeof coords.longitude === 'number' ? coords.longitude : null,
                        accuracy: typeof coords.accuracy === 'number' ? coords.accuracy : null,
                        altitude: typeof coords.altitude === 'number' ? coords.altitude : null,
                        altitudeAccuracy: typeof coords.altitudeAccuracy === 'number' ? coords.altitudeAccuracy : null,
                        heading: typeof coords.heading === 'number' ? coords.heading : null,
                        speed: typeof coords.speed === 'number' ? coords.speed : null,
                        recorded_at: position.timestamp ? new Date(position.timestamp).toISOString() : new Date().toISOString()
                    });
                },
                function(error){
                    let code = typeof error.code === 'number' ? error.code : 'UNKNOWN';
                    const map = { 1: 'PERMISSION_DENIED', 2: 'POSITION_UNAVAILABLE', 3: 'TIMEOUT' };
                    resolve({
                        error: map[code] || (typeof code === 'number' ? String(code) : String(code || 'UNKNOWN')),
                        message: error && error.message ? error.message : ''
                    });
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        });
    }

    function prepareLocationPayload(raw) {
        if (!raw || typeof raw !== 'object') {
            return undefined;
        }

        const payload = {};
        const numericFields = ['latitude','longitude','accuracy','altitude','altitudeAccuracy','heading','speed'];
        numericFields.forEach(function(field){
            if (raw[field] !== undefined && raw[field] !== null && !isNaN(raw[field])) {
                payload[field] = Number(raw[field]);
            }
        });

        if (raw.recorded_at) {
            payload.recorded_at = String(raw.recorded_at);
        }

        if (raw.timestamp && !payload.recorded_at) {
            if (typeof raw.timestamp === 'number') {
                payload.timestamp = raw.timestamp;
            } else {
                payload.timestamp = String(raw.timestamp);
            }
        }

        if (raw.error !== undefined) {
            let code = raw.error;
            if (typeof code === 'number') {
                const map = { 1: 'PERMISSION_DENIED', 2: 'POSITION_UNAVAILABLE', 3: 'TIMEOUT' };
                code = map[code] || String(code);
            }
            payload.error = String(code).toUpperCase();
        }

        if (raw.message) {
            payload.message = String(raw.message);
        }

        return Object.keys(payload).length ? payload : undefined;
    }

    function handleLocationWarning(code) {
        if (locationWarningShown) {
            return;
        }
        const i18n = settings.i18n || {};
        let message = '';
        if (code === 'PERMISSION_DENIED' && i18n.locationDenied) {
            message = i18n.locationDenied;
        } else if (i18n.locationUnavailable) {
            message = i18n.locationUnavailable;
        }
        if (message) {
            showNotice('warning', formatHtml(message));
            locationWarningShown = true;
        }
    }

    $(function(){
        if (settings.summary) {
            updateSummary(settings.summary);
        }
        if (!activeJobId) {
            activeJobId = parseInt($('.arm-tech-time__table').first().data('active-job'), 10) || 0;
        }
        setActiveJob(activeJobId || findActiveJobId());
    });

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
        setActiveJob(jobId);

        captureLocation().then(function(rawLocation){
            const location = prepareLocationPayload(rawLocation);
            if (location && location.error) {
                handleLocationWarning(location.error);
            }

            const payload = { job_id: jobId };
            if (location) {
                payload.location = location;
            }

            request(settings.rest ? settings.rest.start : '', payload, function(response){
                updateRow($row, response, settings.i18n ? settings.i18n.started : 'Timer started.', jobId);
            }, function(error){
                const msg = error && error.message ? error.message : (settings.i18n ? settings.i18n.startError : 'Unable to start timer.');
                showNotice('error', formatHtml(msg));
                setActiveJob(findActiveJobId());
                $btn.prop('disabled', false);
            });
        });
    });

    $(document).on('click', '.arm-time-stop', function(){
        const $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        const jobId   = parseInt($btn.data('job'), 10) || 0;
        const entryId = parseInt($btn.data('entry'), 10) || 0;
        if (!jobId && !entryId) {
            return;
        }
        const $row = $btn.closest('tr');
        $btn.prop('disabled', true);

        captureLocation().then(function(rawLocation){
            const location = prepareLocationPayload(rawLocation);
            if (location && location.error) {
                handleLocationWarning(location.error);
            }

            const payload = {};
            if (entryId) {
                payload.entry_id = entryId;
            }
            if (jobId) {
                payload.job_id = jobId;
            }
            if (location) {
                payload.location = location;
            }

            request(settings.rest ? settings.rest.stop : '', payload, function(response){
                updateRow($row, response, settings.i18n ? settings.i18n.stopped : 'Timer stopped.', jobId);
            }, function(error){
                const msg = error && error.message ? error.message : (settings.i18n ? settings.i18n.stopError : 'Unable to stop timer.');
                showNotice('error', formatHtml(msg));
                setActiveJob(findActiveJobId());
                $btn.prop('disabled', false);
            });
        });
    });
})(jQuery);
