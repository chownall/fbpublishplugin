(function($){
  $(function(){
    var $btn = $('#fbpublish_manual_btn');
    if (!$btn.length) return;
    $btn.on('click', function(){
      var postId = $('#post_ID').val();
      var toPage = $('input[name="fbpublish_post_to_page"]').is(':checked') ? '1' : '0';
      var message = $('textarea[name="fbpublish_custom_message"]').val() || '';
      var force = $('#fbpublish_force').is(':checked') ? '1' : '0';
      var shareAsPhoto = $('#fbpublish_share_as_photo').is(':checked') ? '1' : '0';

      $btn.prop('disabled', true).text('...');
      $.post(FBPUBLISH.ajaxUrl, {
        action: FBPUBLISH.action,
        nonce: FBPUBLISH.nonce,
        postId: postId,
        toPage: toPage,
        message: message,
        force: force,
        shareAsPhoto: shareAsPhoto
      }).done(function(res){
        if (res && res.success) {
          var msg = FBPUBLISH.texts.success;
          if (res.data && res.data.details) {
            var d = res.data.details;
            var parts = [];
            if (d.page) {
              parts.push('page: ' + (d.page.id ? ('ok id=' + d.page.id) : (d.page.skipped ? 'déjà publié' : 'ok')));
            }
            if (parts.length) msg += '\n' + parts.join('\n');
          }
          alert(msg);
        } else {
          var err = FBPUBLISH.texts.error;
          if (res && res.data) {
            if (res.data.message) err += ': ' + res.data.message;
            if (res.data.details) {
              var lines = [];
              var d2 = res.data.details;
              if (d2.page) {
                lines.push('page: ' + (d2.page.error ? d2.page.error : 'inconnu') + (d2.page.status ? (' (HTTP ' + d2.page.status + ')') : ''));
              }
              if (lines.length) err += '\n' + lines.join('\n');
            }
          }
          alert(err);
        }
      }).fail(function(){
        alert(FBPUBLISH.texts.error);
      }).always(function(){
        $btn.prop('disabled', false).text('Publier maintenant sur Facebook');
      });
    });
  });
})(jQuery);


