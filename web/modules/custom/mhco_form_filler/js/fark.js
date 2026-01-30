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
    console.log("access granted");
    (function ($, Drupal, drupalSettings) {
      Drupal.behaviors.my_library = {
        attach: function (context, settings) {
    
          alert(drupalSettings.my_library.some_variable); //alerts the value of PHP's $value
    
        }
      };
    })(jQuery, Drupal, drupalSettings);
  
    
       }) (jQuery, Drupal);
  