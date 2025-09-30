(function($){
  // Simple signature pad (mouse + touch) on a canvas
  function SigPad(canvas){
    var ctx = canvas.getContext('2d');
    var drawing = false, last = null;

    function pos(e){
      if (e.touches && e.touches.length) {
        var rect = canvas.getBoundingClientRect();
        return { x: e.touches[0].clientX - rect.left, y: e.touches[0].clientY - rect.top };
      } else {
        var rect = canvas.getBoundingClientRect();
        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
      }
    }
    function line(a,b){
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.lineWidth = 2;
      ctx.strokeStyle = '#222';
      ctx.beginPath();
      ctx.moveTo(a.x,a.y);
      ctx.lineTo(b.x,b.y);
      ctx.stroke();
    }

    canvas.addEventListener('mousedown', function(e){ drawing=true; last=pos(e); e.preventDefault(); });
    canvas.addEventListener('mousemove', function(e){ if(!drawing) return; var p=pos(e); line(last,p); last=p; e.preventDefault(); });
    window.addEventListener('mouseup', function(){ drawing=false; });

    canvas.addEventListener('touchstart', function(e){ drawing=true; last=pos(e); e.preventDefault(); }, {passive:false});
    canvas.addEventListener('touchmove', function(e){ if(!drawing) return; var p=pos(e); line(last,p); last=p; e.preventDefault(); }, {passive:false});
    window.addEventListener('touchend', function(){ drawing=false; });

    this.clear = function(){ ctx.clearRect(0,0,canvas.width,canvas.height); };
    this.isEmpty = function(){
      // crude heuristic: read a tiny pixel sample
      var data = ctx.getImageData(0,0,canvas.width,canvas.height).data;
      for (var i=3;i<data.length;i+=4){ if (data[i] !== 0) return false; }
      return true;
    };
    this.png = function(){ return canvas.toDataURL('image/png'); };
  }

  function message(text, ok){
    $('#arm-est-msg').text(text).css('color', ok ? '#1a7f37' : '#b91c1c');
  }

  $(function(){
    if (!window.ARM_RE_EST_PUBLIC) return;
    var c = document.getElementById('arm-sig-pad');
    var sig = c ? new SigPad(c) : null;

    $('#arm-sig-clear').on('click', function(){ if(sig) sig.clear(); });

    $('#arm-approve-btn').on('click', function(){
      var name = $.trim($('#arm-sig-name').val());
      if (!name || !sig || sig.isEmpty()) {
        return message(ARM_RE_EST_PUBLIC.i18n.sig_required, false);
      }
      var payload = {
        action: 'arm_re_est_accept',
        nonce: ARM_RE_EST_PUBLIC.nonce,
        token: ARM_RE_EST_PUBLIC.token,
        sig_name: name,
        sig_data: sig.png()
      };
      var $btns = $('#arm-approve-btn, #arm-decline-btn').prop('disabled', true);

      $.post(ARM_RE_EST_PUBLIC.ajax_url, payload)
        .done(function(res){
          if (res && res.success) {
            message(ARM_RE_EST_PUBLIC.i18n.approved, true);
            setTimeout(function(){ location.reload(); }, 800);
          } else {
            message((res && res.data && res.data.msg) ? res.data.msg : ARM_RE_EST_PUBLIC.i18n.error, false);
            $btns.prop('disabled', false);
          }
        })
        .fail(function(){
          message(ARM_RE_EST_PUBLIC.i18n.error, false);
          $btns.prop('disabled', false);
        });
    });

    $('#arm-decline-btn').on('click', function(){
      if (!confirm(ARM_RE_EST_PUBLIC.i18n.confirmDecl)) return;
      var $btns = $('#arm-approve-btn, #arm-decline-btn').prop('disabled', true);
      $.post(ARM_RE_EST_PUBLIC.ajax_url, {
        action: 'arm_re_est_decline',
        nonce: ARM_RE_EST_PUBLIC.nonce,
        token: ARM_RE_EST_PUBLIC.token
      })
      .done(function(res){
        if (res && res.success) {
          message(ARM_RE_EST_PUBLIC.i18n.declined, true);
          setTimeout(function(){ location.reload(); }, 800);
        } else {
          message(ARM_RE_EST_PUBLIC.i18n.error, false);
          $btns.prop('disabled', false);
        }
      })
      .fail(function(){
        message(ARM_RE_EST_PUBLIC.i18n.error, false);
        $btns.prop('disabled', false);
      });
    });
  });
})(jQuery);
