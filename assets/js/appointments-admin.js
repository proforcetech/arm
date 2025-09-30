jQuery(function($){
    const calEl = document.getElementById('arm-appt-calendar');
    if (!calEl) return;

    const calendar = new FullCalendar.Calendar(calEl, {
        initialView: 'dayGridMonth',
        selectable: true,
        editable: true,
        events: function(info, success, failure) {
            $.post(ARM_APPT_ADMIN.ajax_url, {
                action: 'arm_admin_get_appointments',
                nonce: ARM_APPT_ADMIN.nonce,
                start: info.startStr,
                end: info.endStr
            }, function(res){
                if (res.success) success(res.data.events);
                else failure();
            });
        },
        select: function(info) {
            const title = prompt("Enter note for this appointment slot:");
            if (title) {
                $.post(ARM_APPT_ADMIN.ajax_url, {
                    action: 'arm_admin_create_appointment',
                    nonce: ARM_APPT_ADMIN.nonce,
                    start: info.startStr,
                    end: info.endStr,
                    notes: title
                }, function(res){
                    if (res.success) {
                        calendar.refetchEvents();
                    } else {
                        alert("Error creating appointment");
                    }
                });
            }
        },
        eventClick: function(info) {
            if (confirm("Delete this appointment?")) {
                $.post(ARM_APPT_ADMIN.ajax_url, {
                    action: 'arm_admin_delete_appointment',
                    nonce: ARM_APPT_ADMIN.nonce,
                    id: info.event.id
                }, function(res){
                    if (res.success) {
                        info.event.remove();
                    } else {
                        alert("Error deleting appointment");
                    }
                });
            }
        }
    });

    calendar.render();
});

jQuery(function($){
  var calendarEl=document.getElementById('arm-calendar');
  if(!calendarEl) return;
  var calendar=new FullCalendar.Calendar(calendarEl,{
    initialView:'dayGridMonth',
    events:{
      url:ARM_APPT.ajax_url,
      method:'POST',
      extraParams:{action:'arm_admin_events',nonce:ARM_APPT.nonce}
    },
    editable:true,
    eventDrop:function(info){
      $.post(ARM_APPT.ajax_url,{
        action:'arm_save_event',
        nonce:ARM_APPT.nonce,
        id:info.event.id,
        start:info.event.start.toISOString(),
        end:info.event.end.toISOString()
      });
    }
  });
  calendar.render();
});
