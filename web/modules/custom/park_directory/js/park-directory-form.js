(function ($, Drupal, once) {
  Drupal.behaviors.ParkDirectoryForm = {
    attach: function (context, settings) {
      var $this = this;
      $this.AdjustFormFieldID();
    },
    AdjustFormFieldID: function () {
      $('#edit-txtquerytype input').click(function (e) {
        $('#park-directory-form input').each(function () {
          console.log("Removing");
       //   $(this).html.empty();
        });
       // e.preventDefault();
       
        // Add classes instead, clear values
     //   $('#edit-txtname').attr("name", "txtCity");
      });
    },
  };
})(jQuery, Drupal, once);
