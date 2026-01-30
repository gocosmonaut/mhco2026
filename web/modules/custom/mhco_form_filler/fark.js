/**
 * @file
 * Placeholder file for custom sub-theme behaviors.
 *
 */
 (function ($, Drupal) {

  /**
   * Use this behavior as a template for custom Javascript.
   */
  Drupal.behaviors.exampleBehavior = {
    attach: function (context, settings) {
      //alert("I'm alive!");
    }
  };

  var formrandom = Math.random(1000);
  $.get(window.location.origin + "/sites/default/files/form-data/form.txt?v=" + formrandom, function( data, status, xhr) {
  //  console.log( typeof data ); // string
    console.log( xhr ); // HTML content of the jQuery.ajax page
    window.open(data);
  });

  
}) (jQuery, Drupal);
