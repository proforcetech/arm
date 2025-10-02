jQuery(document).ready(function ($) {
    var vehicleFields = (window.ARM_CUSTOMER && Array.isArray(ARM_CUSTOMER.vehicle_fields)) ? ARM_CUSTOMER.vehicle_fields : [];
    var messages = (window.ARM_CUSTOMER && ARM_CUSTOMER.i18n) ? ARM_CUSTOMER.i18n : {};
    var confirmDelete = messages.deleteConfirm || 'Delete this vehicle?';
    var genericError = messages.genericError || 'Something went wrong. Please try again.';

    /** ===== Tab Switching ===== */
    $('.arm-tabs button').on('click', function () {
        var tab = $(this).data('tab');
        $('.arm-tabs button').removeClass('active');
        $(this).addClass('active');
        $('.arm-tab').removeClass('active');
        $('#arm-tab-' + tab).addClass('active');
    });

    var vehicleForm = $('#arm-vehicle-form');

    /** ===== Vehicles: Show Add Form ===== */
    $('.arm-add-vehicle').on('click', function () {
        if (!vehicleForm.length) return;
        var formEl = vehicleForm.find('form')[0];
        if (formEl) {
            formEl.reset();
        }
        vehicleForm.find('input[name=id]').val('');
        vehicleForm.show();
    });

    /** ===== Vehicles: Edit ===== */
    $('.arm-edit-vehicle').on('click', function () {
        if (!vehicleForm.length) return;
        var row = $(this).closest('tr');
        var data = row.data('vehicle') || {};
        var id = $(this).data('id');

        var formEl = vehicleForm.find('form')[0];
        if (formEl) {
            formEl.reset();
        }
        vehicleForm.find('input[name=id]').val(id || '');

        vehicleFields.forEach(function (field) {
            var input = vehicleForm.find('[name="' + field + '"]');
            if (!input.length) return;
            var value = data[field];
            if (value === undefined || value === null) value = '';
            input.val(value);
        });

        vehicleForm.show();
    });

    /** ===== Vehicles: Delete ===== */
    $('.arm-del-vehicle').on('click', function () {
        if (!confirm(confirmDelete)) return;
        var id = $(this).data('id');

        $.post(ARM_CUSTOMER.ajax_url, {
            action: 'arm_vehicle_crud',
            nonce: ARM_CUSTOMER.nonce,
            action_type: 'delete',
            id: id
        }).done(function (resp) {
            if (resp && resp.success) {
                window.location.reload();
            } else {
                var msg = resp && resp.data && resp.data.message ? resp.data.message : genericError;
                window.alert(msg);
            }
        }).fail(function () {
            window.alert(genericError);
        });
    });

    /** ===== Vehicles: Save (Add/Edit) ===== */
    vehicleForm.find('form').on('submit', function (e) {
        e.preventDefault();
        var formData = $(this).serializeArray();
        var payload = {
            action: 'arm_vehicle_crud',
            nonce: ARM_CUSTOMER.nonce
        };

        formData.forEach(function (field) {
            payload[field.name] = field.value;
        });

        payload.action_type = payload.id ? 'edit' : 'add';

        $.post(ARM_CUSTOMER.ajax_url, payload).done(function (resp) {
            if (resp && resp.success) {
                window.location.reload();
            } else {
                var msg = resp && resp.data && resp.data.message ? resp.data.message : genericError;
                window.alert(msg);
            }
        }).fail(function () {
            window.alert(genericError);
        });
    });
});
