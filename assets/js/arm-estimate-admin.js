/* global jQuery, ARM_RE_EST */
(function ($) {
  'use strict';
  if (typeof ARM_RE_EST === 'undefined') return;

  const $doc = $(document);
  const taxApply = ARM_RE_EST.taxApply || 'parts_labor';

  function parseNum(v) {
    const n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }

  function nextIndex() {
    const $rows = $('#arm-items-table tbody tr');
    return $rows.length ? ($rows.length) : 0;
  }

  function rowTemplate(i, def) {
    def = def || { type:'LABOR', desc:'', qty:1, price:parseNum(ARM_RE_EST.defaultLabor || 0), taxable:1, total:0 };
    const types = ['LABOR','PART','FEE','DISCOUNT'];
    const opts = types.map(t => '<option value="'+t+'"'+(t===def.type?' selected':'')+'>'+t+'</option>').join('');
    return '<tr data-row="'+i+'">'
      + '<td><select name="items['+i+'][type]" class="arm-it-type">'+opts+'</select></td>'
      + '<td><input type="text" name="items['+i+'][desc]" value="'+(def.desc||'')+'" class="widefat"></td>'
      + '<td><input type="number" step="0.01" name="items['+i+'][qty]" value="'+(def.qty||1)+'" class="small-text arm-it-qty"></td>'
      + '<td><input type="number" step="0.01" name="items['+i+'][price]" value="'+(def.price||0)+'" class="regular-text arm-it-price"></td>'
      + '<td><input type="checkbox" name="items['+i+'][taxable]" value="1" '+(def.taxable? 'checked':'')+' class="arm-it-taxable"></td>'
      + '<td class="arm-it-total">'+(def.total ? def.total.toFixed(2) : '0.00')+'</td>'
      + '<td><button type="button" class="button arm-remove-item">&times;</button></td>'
      + '</tr>';
  }

  function lineIsTaxable(lineType, taxableChecked) {
    if (!taxableChecked) return false;
    if (taxApply === 'parts_only') {
      return lineType === 'PART';
    }
    return true; // parts & labor
  }

  function recalc() {
    let subtotal = 0, taxable = 0;
    $('#arm-items-table tbody tr').each(function () {
      const $tr = $(this);
      const type = $tr.find('.arm-it-type').val();
      const qty  = parseNum($tr.find('.arm-it-qty').val());
      const unit = parseNum($tr.find('.arm-it-price').val());
      const isTax = $tr.find('.arm-it-taxable').is(':checked');

      let line = qty * unit;
      if (type === 'DISCOUNT') line = -line;

      subtotal += line;
      if (lineIsTaxable(type, isTax)) {
        taxable += Math.max(0, line);
      }
      $tr.find('.arm-it-total').text(line.toFixed(2));
    });

    const rate = parseNum($('#arm-tax-rate').val());
    const tax  = +(taxable * (rate/100)).toFixed(2);
    const total = +(subtotal + tax).toFixed(2);

    $('#arm-subtotal').val(subtotal.toFixed(2));
    $('#arm-tax').val(tax.toFixed(2));
    $('#arm-total').val(total.toFixed(2));
  }

  // Item events
  $doc.on('input change', '.arm-it-qty, .arm-it-price, .arm-it-taxable, .arm-it-type', recalc);

  // Add item
  $doc.on('click', '#arm-add-item', function () {
    const i = nextIndex();
    $('#arm-items-table tbody').append(rowTemplate(i));
    recalc();
  });

  // Remove item
  $doc.on('click', '.arm-remove-item', function () {
    $(this).closest('tr').remove();
    // Renumber to keep indexes sequential (optional; PHP can handle sparse)
    $('#arm-items-table tbody tr').each(function (idx) {
      const $tr = $(this);
      $tr.attr('data-row', idx);
      $tr.find('select, input').each(function(){
        const name = $(this).attr('name');
        if (!name) return;
        $(this).attr('name', name.replace(/items\[\d+\]/, 'items['+idx+']'));
      });
    });
    recalc();
  });

  // Insert Travel/Call-out fees into items
  $doc.on('click', '#arm-insert-travel', function () {
    const calloutAmt = parseNum($('#arm-callout-amount').val());
    const miles = parseNum($('#arm-mileage-miles').val());
    const perMile = parseNum($('#arm-mileage-rate').val());

    const feeTaxable = (ARM_RE_EST.taxApply === 'parts_labor') ? 1 : 0;

    if (calloutAmt > 0) {
      const i = nextIndex();
      $('#arm-items-table tbody').append(rowTemplate(i, {
        type: 'FEE',
        desc: 'Call-out Fee',
        qty: 1,
        price: calloutAmt,
        taxable: feeTaxable
      }));
    }
    if (miles > 0 && perMile > 0) {
      const i2 = nextIndex();
      $('#arm-items-table tbody').append(rowTemplate(i2, {
        type: 'FEE',
        desc: 'Mileage ('+miles+' @ '+perMile.toFixed(2)+')',
        qty: miles,
        price: perMile,
        taxable: feeTaxable
      }));
    }
    recalc();
  });

  // Customer typeahead
  (function initCustomerSearch(){
    const $box = $('#arm-customer-search');
    const $hid = $('#arm-customer-id');
    const $results = $('#arm-customer-results');

    if (!$box.length) return;

    let timer = null;
    function hideResults(){ $results.hide().empty(); }

    function fillCustomer(c) {
      $hid.val(c.id);
      $('#arm-customer-fields [name=c_first_name]').val(c.first_name || '');
      $('#arm-customer-fields [name=c_last_name]').val(c.last_name || '');
      $('#arm-customer-fields [name=c_email]').val(c.email || '');
      $('#arm-customer-fields [name=c_phone]').val(c.phone || '');
      $('#arm-customer-fields [name=c_address]').val(c.address || '');
      $('#arm-customer-fields [name=c_city]').val(c.city || '');
      $('#arm-customer-fields [name=c_zip]').val(c.zip || '');
    }

    $box.on('input', function(){
      const q = $box.val().trim();
      $hid.val(''); // reset until chosen
      if (timer) clearTimeout(timer);
      if (q.length < 2) { hideResults(); return; }

      timer = setTimeout(function(){
        $.post(ARM_RE_EST.ajax_url, {
          action: 'arm_re_customer_search',
          nonce: ARM_RE_EST.nonce,
          q: q
        }).done(function(res){
          if (!res || !res.success || !res.data || !res.data.results) { hideResults(); return; }
          const items = res.data.results;
          if (!items.length) { hideResults(); return; }
          $results.empty();
          items.forEach(function(it){
            const $a = $('<a href="#" class="arm-typeahead-item" style="display:block;padding:6px 8px;border-bottom:1px solid #eee;"></a>');
            $a.text(it.label);
            $a.data('row', it);
            $results.append($a);
          });
          $results.show();
        }).fail(hideResults);
      }, 250);
    });

    $results.on('click', '.arm-typeahead-item', function(e){
      e.preventDefault();
      const data = $(this).data('row') || {};
      fillCustomer(data);
      $box.val(data.label || '');
      hideResults();
    });

    $(document).on('click', function(e){
      if (!$(e.target).closest('#arm-customer-search, #arm-customer-results').length) hideResults();
    });
  })();

  // Kick off an initial calc after load
  $(recalc);

})(jQuery);
