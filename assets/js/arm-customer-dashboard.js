jQuery(document).ready(function ($) {
    /** ===== Tab Switching ===== */
    $(".arm-tabs button").on("click", function () {
        $(".arm-tabs button").removeClass("active");
        $(this).addClass("active");

        var tab = $(this).data("tab");
        $(".arm-tab").removeClass("active");
        $("#tab-" + tab).addClass("active");
    });

    /** ===== Vehicles: Show Add Form ===== */
    $(".arm-add-vehicle").on("click", function () {
        $("#arm-vehicle-form").show().find("form")[0].reset();
        $("#arm-vehicle-form input[name=id]").val("");
    });

    /** ===== Vehicles: Edit ===== */
    $(".arm-edit-vehicle").on("click", function () {
        var btn = $(this);
        var formWrap = $("#arm-vehicle-form");
        var form = formWrap.find("form");
        form[0].reset();

        formWrap.show();
        form.find("input[name=id]").val(btn.data("id") || "");
        form.find("input[name=year]").val(btn.data("year") || "");
        form.find("input[name=make]").val(btn.data("make") || "");
        form.find("input[name=model]").val(btn.data("model") || "");
        form.find("input[name=trim]").val(btn.data("trim") || "");
        form.find("input[name=engine]").val(btn.data("engine") || "");
        form.find("input[name=drive]").val(btn.data("drive") || "");
        form.find("input[name=vin]").val(btn.data("vin") || "");
        form.find("input[name=license_plate]").val(btn.data("license_plate") || "");
        form.find("input[name=current_mileage]").val(btn.data("current_mileage") || "");
        form.find("input[name=previous_service_mileage]").val(btn.data("previous_service_mileage") || "");
    });

    /** ===== Vehicles: Delete ===== */
    $(".arm-del-vehicle").on("click", function () {
        if (!confirm("Delete this vehicle?")) return;
        var id = $(this).data("id");

        $.post(ARM_CUSTOMER.ajax_url, {
            action: "arm_vehicle_crud",
            nonce: ARM_CUSTOMER.nonce,
            action_type: "delete",
            id: id
        }, function (resp) {
            if (resp.success) {
                location.reload();
            } else {
                alert(resp.data.message || "Error");
            }
        });
    });

    /** ===== Vehicles: Save (Add/Edit) ===== */
    $("#arm-vehicle-form form").on("submit", function (e) {
        e.preventDefault();

        var formData = $(this).serializeArray();
        var payload = {
            action: "arm_vehicle_crud",
            nonce: ARM_CUSTOMER.nonce,
        };
        formData.forEach(function (f) {
            payload[f.name] = f.value;
        });
        payload["action_type"] = payload.id ? "edit" : "add";

        $.post(ARM_CUSTOMER.ajax_url, payload, function (resp) {
            if (resp.success) {
                location.reload();
            } else {
                alert(resp.data.message || "Error");
            }
        });
    });
});
