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
        var row = $(this).closest("tr");
        var id = $(this).data("id");

        $("#arm-vehicle-form").show();
        $("#arm-vehicle-form input[name=id]").val(id);
        $("#arm-vehicle-form input[name=year]").val(row.find("td").eq(0).text());
        $("#arm-vehicle-form input[name=make]").val(row.find("td").eq(1).text());
        $("#arm-vehicle-form input[name=model]").val(row.find("td").eq(2).text());
        // engine/trim not in table -> left blank
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
