/**
 * @file
 * MHCO Form Filler specific JavaScript.
 */
(function ($, Drupal, once) {
  Drupal.behaviors.mhcoFormFiller = {
    attach: function (context, settings) {
      
      // DEBUG 1: Is the file actually loading?
      console.log("MHCO Form Filler script initialized.");
      
      // 1. Simplify the selector to catch anything with the form-button class
      var downloadButtons = once('mhcoFormFillerAction', '.form-button', context);
      
      // DEBUG 2: Did it find the buttons?
      if (downloadButtons.length > 0) {
        console.log("Success: Found " + downloadButtons.length + " buttons ready for download.");
      }

      // 2. Wrap the resulting elements in jQuery to bind the click event
      $(downloadButtons).on('click', function (e) {
        e.preventDefault(); 
        
        // DEBUG 3: Did the click register?
        console.log("Download button clicked!");

        var $btn = $(this);
        var formFID = $btn.attr("id");
        var formNID = $btn.attr("nid");
        var formDL = $btn.attr("dl");
        var formTitle = $btn.attr("title");
        var formNumber = formFID ? formFID.slice(1) : 'Unknown';

        // 3. Find the Admin Selector using the input name (safest method in Drupal)
        var targetUid = null;
        var targetUserInput = $('input[name="target_user"]').val();
        
        if (targetUserInput) {
          var match = targetUserInput.match(/\((\d+)\)$/);
          if (match && match[1]) {
            targetUid = match[1];
            console.log("Admin override active. Target UID:", targetUid);
          }
        }

        // 4. Add loading state UI
        $btn.css('opacity', '0.5');
        $btn.text('...'); 

        // 5. Send the AJAX request
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
            $btn.css('opacity', '1');
            $btn.text(formNumber); 
            if (response.pdf_url) {
               console.log("PDF generated successfully.");
               window.open(response.pdf_url, '_blank');
            } else {
               alert("Error: Could not generate PDF.");
            }
          },
          error: function(xhr, status, error) {
            $btn.css('opacity', '1');
            $btn.text(formNumber);
            console.error("AJAX Error:", error);
            alert("A server error occurred while processing your request.");
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);