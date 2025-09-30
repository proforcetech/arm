/* global jQuery, ARM_RE */
(function bootstrap(factory) {
  // Ensure jQuery exists (Rocket Loader/async order safe)
  if (typeof window.jQuery !== 'undefined') {
    factory(window.jQuery);
  } else {
    // Try again when DOM is ready
    document.addEventListener('DOMContentLoaded', function () {
      if (typeof window.jQuery !== 'undefined') {
        factory(window.jQuery);
      } else {
        // Last resort: wait a bit for Rocket Loader to release jQuery
        var tries = 0, t = setInterval(function () {
          if (typeof window.jQuery !== 'undefined' || ++tries > 20) {
            clearInterval(t);
            if (typeof window.jQuery !== 'undefined') factory(window.jQuery);
            else console.error('[ARM RE] jQuery not available — frontend halted.');
          }
        }, 50);
      }
    });
  }
})(function ($) {
  'use strict';

  // If the form isn't on the page, exit early (avoid needless work)
  if (!document.getElementById('arm-repair-estimate-form')) return;

  const HIER = ['year', 'make', 'model', 'engine', 'drive', 'trim'];
  const IDS = HIER.map(h => '#arm_' + h);

  /** Track latest request per level to avoid race-conditions */
  const pendingKeyByLevel = Object.create(null);

  function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

  function setSelectOptions($sel, options, placeholder) {
    if (!$sel || !$sel.length) return;
    const label = placeholder || ('Select ' + cap(($sel.attr('id') || '').replace('arm_', '')));
    const $ph = $('<option>').val('').text(label);
    $sel.empty().append($ph);

    if (Array.isArray(options) && options.length) {
      for (let i = 0; i < options.length; i++) {
        $sel.append($('<option>').val(options[i]).text(options[i]));
      }
      $sel.prop('disabled', false);
    } else {
      $sel.prop('disabled', true);
    }
  }

  function clearDownstream(fromIdx) {
    for (let i = fromIdx + 1; i < HIER.length; i++) {
      const lvl = HIER[i];
      setSelectOptions($('#arm_' + lvl), [], 'Select ' + cap(lvl));
      pendingKeyByLevel[lvl] = null;
    }
  }

  function toggleOther(on) {
    for (let i = 0; i < IDS.length; i++) $(IDS[i]).prop('disabled', !!on);
    $('#arm_other_text').toggle(!!on);
    if (on) {
      for (let i = 0; i < IDS.length; i++) $(IDS[i]).val('');
      clearDownstream(-1);
    }
  }

  function syncServiceAddress() {
    const checked = $('#arm_same_addr').is(':checked');
    const $addr = $('#arm_srv_addr'), $city = $('#arm_srv_city'), $zip = $('#arm_srv_zip');
    if (checked) {
      $addr.val($('#arm_cust_addr').val()).prop('readonly', true);
      $city.val($('#arm_cust_city').val()).prop('readonly', true);
      $zip.val($('#arm_cust_zip').val()).prop('readonly', true);
    } else {
      $addr.prop('readonly', false);
      $city.prop('readonly', false);
      $zip.prop('readonly', false);
    }
  }

  function normalizeDelivery() {
    const email = $('#arm_del_email').is(':checked');
    const sms   = $('#arm_del_sms').is(':checked');
    const both  = $('#arm_del_both').is(':checked');

    if (both || (email && sms)) {
      $('#arm_del_both').prop('checked', true);
      $('#arm_del_email, #arm_del_sms').prop('checked', false).prop('disabled', true);
    } else {
      $('#arm_del_email, #arm_del_sms').prop('disabled', false);
    }
  }

  function ensureConfig(cb) {
    // Wait for ARM_RE localization; Rocket Loader can reorder localized blocks
    if (typeof window.ARM_RE !== 'undefined') return cb();
    var tries = 0, t = setInterval(function () {
      if (typeof window.ARM_RE !== 'undefined' || ++tries > 40) {
        clearInterval(t);
        if (typeof window.ARM_RE !== 'undefined') cb();
        else console.error('[ARM RE] ARM_RE localization missing — frontend halted.');
      }
    }, 50);
  }

  function fetchOptions(nextLevel, filters) {
    if (typeof window.ARM_RE === 'undefined' || !ARM_RE.ajax_url) return $.Deferred().reject().promise();
    const key = nextLevel + '|' + JSON.stringify(filters || {});
    pendingKeyByLevel[nextLevel] = key;

    return $.post(ARM_RE.ajax_url, $.extend({
      action: 'arm_get_vehicle_options',
      nonce: ARM_RE.nonce,
      next: nextLevel
    }, filters || {}))
    .done(function (res) {
      if (pendingKeyByLevel[nextLevel] !== key) return; // stale response
      if (res && res.success) {
        setSelectOptions($('#arm_' + nextLevel), res.data.options, 'Select ' + cap(nextLevel));
      } else {
        setSelectOptions($('#arm_' + nextLevel), [], 'Select ' + cap(nextLevel));
      }
    })
    .fail(function () {
      if (pendingKeyByLevel[nextLevel] !== key) return;
      setSelectOptions($('#arm_' + nextLevel), [], 'Select ' + cap(nextLevel));
    });
  }

  // Event wiring (after config present)
  ensureConfig(function () {
    // Initialize Year
    if (Array.isArray(window.ARM_RE_INIT_YEARS) && window.ARM_RE_INIT_YEARS.length) {
      setSelectOptions($('#arm_year'), window.ARM_RE_INIT_YEARS, 'Select Year');
    } else {
      fetchOptions('year', {});
    }

    // Dynamic chain
    for (let idx = 0; idx < HIER.length; idx++) {
      (function (level, i) {
        $('#arm_' + level).on('change', function () {
          clearDownstream(i);
          if (i === HIER.length - 1) return;

          const filters = {};
          for (let j = 0; j <= i; j++) {
            const val = $('#arm_' + HIER[j]).val();
            if (!val) return;
            filters[HIER[j]] = val;
          }
          const next = HIER[i + 1];
          fetchOptions(next, filters);
        });
      })(HIER[idx], idx);
    }

    // "Other" vehicle toggle
    $('#arm_other_toggle').on('change', function () { toggleOther(this.checked); });

    // Service address sync
    $('#arm_same_addr').on('change', syncServiceAddress);
    $('#arm_cust_addr, #arm_cust_city, #arm_cust_zip').on('input', function () {
      if ($('#arm_same_addr').is(':checked')) syncServiceAddress();
    });

    // Delivery normalization
    $(document).on('change', '#arm_del_email, #arm_del_sms, #arm_del_both', normalizeDelivery);

    // Initial UI
    normalizeDelivery();
    syncServiceAddress();
    toggleOther($('#arm_other_toggle').is(':checked'));

    // Submit
    $('#arm-repair-estimate-form').on('submit', function (e) {
      e.preventDefault();
      if (typeof window.ARM_RE === 'undefined' || !ARM_RE.ajax_url) {
        alert('Unable to submit: configuration missing.');
        return;
      }

      const $form = $(this);
      const $msg = $('#arm_msg');

      if (!$('#arm_terms').is(':checked')) {
        $msg.text(ARM_RE.msgs.required).removeClass('arm-ok').addClass('arm-error').show();
        return;
      }
      const anyDelivery = $('#arm_del_email').is(':checked') || $('#arm_del_sms').is(':checked') || $('#arm_del_both').is(':checked');
      if (!anyDelivery) {
        $msg.text(ARM_RE.msgs.required).removeClass('arm-ok').addClass('arm-error').show();
        return;
      }

      const payload = {};
      $.each($form.serializeArray(), function (_, x) { payload[x.name] = x.value; });
      payload.action = 'arm_submit_estimate';
      payload.nonce = ARM_RE.nonce;

      const $btn = $form.find('button[type=submit]').prop('disabled', true);

      $.post(ARM_RE.ajax_url, payload)
        .done(function (res) {
          if (res && res.success) {
            $msg.text(ARM_RE.msgs.ok).removeClass('arm-error').addClass('arm-ok').show();
            $form[0].reset();
            clearDownstream(-1);
            if (Array.isArray(window.ARM_RE_INIT_YEARS) && window.ARM_RE_INIT_YEARS.length) {
              setSelectOptions($('#arm_year'), window.ARM_RE_INIT_YEARS, 'Select Year');
            } else {
              fetchOptions('year', {});
            }
            toggleOther(false);
            normalizeDelivery();
            syncServiceAddress();
          } else {
            const serverMsg = res && res.data && res.data.message ? res.data.message : ARM_RE.msgs.error;
            $msg.text(serverMsg).removeClass('arm-ok').addClass('arm-error').show();
          }
        })
        .fail(function () {
          $msg.text(ARM_RE.msgs.error).removeClass('arm-ok').addClass('arm-error').show();
        })
        .always(function () {
          $btn.prop('disabled', false);
        });
    });
  });
});


// Appointment slot loading
$('#arm_appt_date').on('change', function(){
  var d = $(this).val();
  if (!d) return;
  $.post(ARM_RE.ajax_url,{
    action:'arm_get_slots',
    nonce:ARM_RE.nonce,
    date:d
  }, function(res){
    if (res.success) {
      var $sel = $('#arm_appt_slot');
      $sel.empty();
      if (res.data.holiday) {
        $('#arm_appt_slots_wrap').hide();
        $('#arm_appt_msg').text('Closed for: '+res.data.label).show();
        return;
      }
      if (!res.data.slots.length) {
        $('#arm_appt_slots_wrap').hide();
        $('#arm_appt_msg').text('No available slots').show();
        return;
      }
      res.data.slots.forEach(function(s){
        $sel.append($('<option>').val(s).text(s));
      });
      $('#arm_appt_slots_wrap').show();
      $('#arm_appt_msg').hide();
    }
  });
});
