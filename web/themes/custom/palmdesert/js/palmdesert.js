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
    },
  };

  // Global DOM
  var currentLoc = window.location.href;
  var rootURL =
    window.location.protocol + "//" + window.location.hostname + "/";
  var loggedIn = $("body").hasClass("user-logged-in");
  var onHomePage = $("body").hasClass("path-frontpage");
  var previousLoc = document.referrer;
  var userLanguage = navigator.language;
  var currentTime = Date.now();
  var viewportWidth = $(window).width();
  var viewportHeight = $(window).height();
  var bodyHeight = $(document).height();
  var formsPage = $("body").hasClass("mhco-forms-grid");
  var isNews = $("body").hasClass("section-news-and-resources");

  // Give nav bar a shadow when the page gets scrolled
  $(window).scroll(function () {
    var scrollTop = $(window).scrollTop();
    if (scrollTop >= 50) {
      $("#top-bar-sticky-container").addClass("scrolldown");
      $("#top-bar-sticky-container").removeClass("scrollup");
    } else {
      $("#top-bar-sticky-container").removeClass("scrolldown");
      $("#top-bar-sticky-container").addClass("scrollup");
    }
  });

  // Safari detection for form downloads

  $(document).ready(function () {
  var isSafari = /constructor/i.test(window.HTMLElement) || (function (p) { return p.toString() === "[object SafariRemoteNotification]"; })(!window['safari'] || (typeof safari !== 'undefined' && window['safari'].pushNotification));
 if (formsPage == true && isSafari == true) {
  console.log("Forms page on Safari!");
  $('h4.safari-warning').removeClass("warning-off");
 }});
 

  // Remove articles from anonymous users
  $(document).ready(function () {
    var auth = $('body').hasClass("user-not-logged-in"); 
    var column = $('body').hasClass("node--type-column");
    var cu = $('body').hasClass("node--type-community-updates");
    if (auth == true) {
      if (column == true || cu == true) {
      console.log("anon user " + auth);
      $('article').remove();
    }}
  });

  $(document).ready(function () {
    setTimeout(function () {
      if (onHomePage == true) {
        $(".home-article-search input#edit-tid").attr("placeholder", "Enter an article topic.");
        $(".home-article-search input#edit-tid").addClass("shitballs");
      }
    }, 5000);
  });

  // Hide home page article search help text after search occurs
  $(document).ready(function () {
  $(".success").click(function () {
    setTimeout(function () {
      $(".assist").css('display', 'none');
      $(".js-form-type-entity-autocomplete").css('float', 'none !important');
    }, 5000);
  });
  });



  // Fade in menu and content areas because of weird loading hiccups
  $(document).ready(function () {
    setTimeout(function () {
      $("#block-salem-main-menu").addClass("fade-in");
    }, 200);
    setTimeout(function () {
      $("main").addClass("fade-in");
    }, 300);
  });

  // Remove extra spaces from columns and community updates
  $(document).ready(function () {
    if (isNews) {
      $('p').each(function (index, value) {
        var extraSpaces = $(this).html();
        if (extraSpaces.length <= 19) {
          $(this).remove();
        }
      })
    }
  });

  $(document).ready(function () {
    console.log("Ready");

    // Send information to custom form filler module
    // $('a.form-button').click( function() { // node
    // below is views - other changes would have to be made.
    $(".form-download-div").click(function () {
      var profileName = $(".profile-name").text().trim();
      var profilePark = $(".profile-park").text().trim();
      var profileAddress = $(".profile-address").text().trim();
      var profileLocation = $(".profile-location").text().trim();
      var profileState = $(".profile-state").text().trim();
      var profileZIP = $(".profile-zip").text().trim();
      var profilePhone = $(".profile-phone").text().trim();
      var profileCombined = $(".profile-combined").text().trim();
      var profileUID = $(".profile-uid").text().trim();
      var formFID = $(this).attr("id");
      var formNID = $(this).attr("nid");
      var formDL = $(this).attr("dl");
      var formTitle = $(this).attr("title");
      var formNumber = $(this).attr("id").slice(1);
      // var formnumber = ( $(this).attr('href') ); // use ID but not a pure integer, so F1, F82, etc
      // e.preventDefault();

      $.ajax({
        method: "POST",
        url: "/web/modules/custom/mhco_form_filler/mhco_form_filter.module",
        data: {
          park: profilePark,
          parkName: profileName,
          parkAddress: profileAddress,
          parkLocation: profileLocation,
          parkState: profileState,
          parkZIP: profileZIP,
          parkPhone: profilePhone,
          parkCombined: profileCombined,
          parkUID: profileUID,
          formID: formFID,
          formNID: formNID,
          downloadLink: formDL,
          formName: formTitle,
          formNo: formNumber,
        },
      });
    });
  });
})(jQuery, Drupal);
