(function($){
  $(function(){
    var $btn = $('#fbpublish_manual_btn');
    if (!$btn.length) return;
    $btn.on('click', function(){
      var postId = $('#post_ID').val();
      var toPage = $('input[name="fbpublish_post_to_page"]').is(':checked') ? '1' : '0';
      var toGroup = $('input[name="fbpublish_post_to_group"]').is(':checked') ? '1' : '0';
      var message = $('textarea[name="fbpublish_custom_message"]').val() || '';

      $btn.prop('disabled', true).text('...');
      $.post(FBPUBLISH.ajaxUrl, {
        action: FBPUBLISH.action,
        nonce: FBPUBLISH.nonce,
        postId: postId,
        toPage: toPage,
        toGroup: toGroup,
        message: message
      }).done(function(res){
        if (res && res.success) {
          alert(FBPUBLISH.texts.success);
        } else {
          alert(FBPUBLISH.texts.error + (res && res.data && res.data.message ? (': ' + res.data.message) : ''));
        }
      }).fail(function(){
        alert(FBPUBLISH.texts.error);
      }).always(function(){
        $btn.prop('disabled', false).text('Publier maintenant sur Facebook');
      });
    });
  });
})(jQuery);


