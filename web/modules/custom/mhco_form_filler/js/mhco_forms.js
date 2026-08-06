/**
 * @file
 * MHCO Form Filler specific JavaScript.
 */
(function ($, Drupal, once) {
  Drupal.behaviors.mhcoFormFiller = {
    attach: function (context, settings) {
      
      // We bind this event to the 'body' element just ONE time.
      // It now listens for clicks on the download div OR the most-downloaded-forms list item
      $(once('mhcoFormGlobalBinder', 'body', context)).on('click', '.form-download-div', function (e) {
        e.preventDefault(); 
        
        console.log("Row clicked!");

        var $row = $(this);
        var $btn = $row.find('.form-button');
        
        // Extract variables
        var formFID = $btn.attr("id");
        var formNID = $btn.attr("nid");
        var formDL = $btn.attr("dl");
        var formTitle = $btn.attr("title");
        var formNumber = formFID ? formFID.slice(1) : 'Unknown';

        // Check for Admin override
        var targetUid = null;
        var targetUserInput = $('input[name="target_user"]').val();
        
        if (targetUserInput) {
          var match = targetUserInput.match(/\((\d+)\)$/);
          if (match && match[1]) {
            targetUid = match[1];
          }
        }

        // Add loading state UI using the correct object syntax for multiple CSS properties
        $btn.css({
          'opacity': '0.5', 
          'font-size': '18px',
        }).text('Creating PDF'); 

        // Send the AJAX request
        $.ajax({
          method: "POST",
          url: "/mhco-forms/generate-pdf",
          data: {
            formID: formFID,
            formNID: formNID,
            downloadLink: formDL,
            formName: formTitle,
            formNo: formNumber,
            target_uid: targetUid
          },
          success: function(response) {
            // Restore button UI and clear the inline font-size so it reverts to the stylesheet default
            $btn.css({
              'opacity': '1',
              'font-size': ''
            }).text(formNumber); 
            
            if (response.pdf_url) {
               window.open(response.pdf_url, '_blank');
            } else {
               alert("Error: Could not generate PDF.");
            }
          },
          error: function(xhr, status, error) {
            // Restore button UI on error
            $btn.css({
              'opacity': '1',
              'font-size': ''
            }).text(formNumber);
            
            console.error("AJAX Error:", error);
            alert("A server error occurred while processing your request.");
          }
        });
      });
      
    }
  };
})(jQuery, Drupal, once);