/**
 * @file
 * Shows status messages in a modal instead of inline.
 *
 */
(function ($, Drupal) {

  /**
   * Displays status messages in a Foundation reveal modal.
   */
  Drupal.behaviors.foundationStatusInReveal = {
    attach: function (context, settings) {
      $(once('foundation-reveal', '#status-messages')).each(function() {
        // Trigger the reveal popup.
        var $messages = $(this);
        $messages.foundation('open');
      });
    }
  };

})(jQuery, Drupal);
