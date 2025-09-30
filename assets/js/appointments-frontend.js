jQuery(function($){
    const form = $('#arm-appointment-form');
    if (!form.length) return;

    const estimateId = form.data('estimate');

    // Load slots
    $.post(ARM_APPT.ajax_url, {
        action: 'arm_get_slots',
        nonce: ARM_APPT.nonce,
        estimate_id: estimateId
    }, function(res){
        if (!res.success || !res.data.slots) {
            $('#arm-appointment-slots').html('<p>'+ARM_APPT.msgs.error+'</p>');
            return;
        }
        let html = '<ul class="arm-slot-list">';
        res.data.slots.forEach(slot=>{
            html += `<li><label><input type="radio" name="slot" value="${slot.start}|${slot.end}"> ${slot.start} – ${slot.end}</label></li>`;
        });
        html += '</ul>';
        $('#arm-appointment-slots').html(html);
    });

    // Book slot
    form.on('submit', function(e){
        e.preventDefault();
        const sel = $('input[name="slot"]:checked').val();
        if (!sel) {
            alert(ARM_APPT.msgs.choose_slot);
            return;
        }
        const [start,end] = sel.split('|');

        $.post(ARM_APPT.ajax_url, {
            action: 'arm_book_slot',
            nonce: ARM_APPT.nonce,
            estimate_id: estimateId,
            start: start,
            end: end,
            customer_id: ARM_APPT.customer_id || 0
        }, function(res){
            if (res.success) {
                $('#arm-appt-msg').html('<p>'+ARM_APPT.msgs.booked+'</p>');
                form.find('button').prop('disabled',true);
            } else {
                $('#arm-appt-msg').html('<p>'+ARM_APPT.msgs.error+'</p>');
            }
        });
    });
});
