/**
 * @file
 * Global utilities.
 *
 */
(function ($, Drupal) {

  'use strict';

  Drupal.behaviors.palmdesert = {
    attach: function (context, settings) {

      var $this = this;
      $this.MarqueeMatchHeight();
      $this.ArticleTitleCapitalization();
      $this.ArticleSpaces();
      $this.ArticleSearch();
      $this.LinkMarquee();
      $this.LinkMarqueeArticle();
      $this.MarqueeRandomizer();
      $this.ArticleColumnCategoryHighlighting();
      $this.SearchRedirect();
      $this.NavAnimate();
      $this.FormClick();
      $this.TermBodyTrim();
      $this.RemoveAnonArticles();
    },
    MarqueeMatchHeight: function () {
      var slideshow_height = $('.view-display-id-marquee_slideshow').height();
      var slideshow_width = $('.view-display-id-marquee_slideshow').width();
      var column_two_height = $('#home-col-2').height();
      if (slideshow_height < column_two_height) {
        $('.view-display-id-marquee_slideshow').height(column_two_height);
      }
      $('.views_slideshow_cycle_teaser_section').height(column_two_height);
      $('.views_slideshow_cycle_slide .views-row article').width(slideshow_width);
      var slideshow_height = $('.view-display-id-marquee_slideshow').height();
      $('.views_slideshow_cycle_slide .views-row article').height(slideshow_height);
    },

    ArticleTitleCapitalization: function () {
      // Combine selectors into a single comma-separated string for jQuery
      var selectors = '.views-field-title h5 a, h1.title span';

      // Define the lowercase exceptions
      var exceptions = ["to", "the", "and", "or", "for", "of", "in", "on", "with", "an"];

      // Run a single loop over every matching element found on the page
      $(selectors).each(function () {
        var currentTitle = $(this).text().toLowerCase();

        // Split the title into an array of individual words
        var words = currentTitle.split(' ');

        var transformedWords = words.map(function (word, index) {

          // 1. Check if the word CONTAINS "q&a" to catch cases with punctuation (e.g., "Q&A:")
          if (word.toLowerCase().includes('q&a')) {
            // Replace 'q&a' with 'Q&A' but leave the punctuation intact
            return word.replace(/q&a/i, 'Q&A');
          }

          // 2. Always capitalize the first word. 
          // For other words, only capitalize if they are NOT in the exceptions list.
          if (index === 0 || !exceptions.includes(word)) {
            return word.charAt(0).toUpperCase() + word.slice(1);
          }

          // 3. Otherwise, leave it lowercase
          return word;
        });
        // Join the words back into a single string
        var transformedTitle = transformedWords.join(' ');

        $(this).text(transformedTitle);
      });
    },

    ArticleSpaces: function () {
      var isArticle = $("#page").hasClass("article");
      if (isArticle) {
        $('p').each(function (index, value) {
          var extraSpaces = $(this).html();
          if (extraSpaces.length <= 19) {
            $(this).remove();
          }
        })
      }
      $('.field-content').each(function (index, value) {
        var extraSpaces = $(this).html();
        if (extraSpaces.length <= 19) {
          // $(this).remove();
        }
      })

      $('.views-field-field-question-or-teaser').each(function (index, value) {
        var updatedHtml = $(this).html().replace(/&nbsp;/g, '');
        $(this).html(updatedHtml);
      })
    },
    ArticleSearch: function () {
      $('#edit-submit-article-search').click(function (event) {
        $('#article-tags').attr('style', 'flex-basis: 33.333%');
        $('#article-search').attr('style', 'flex-basis: 66.666%');
        $('.view-display-id-home_page_articles').remove();
      });
    },

    LinkMarquee: function () {
      $('.node--type-marquee-promotion').each(function () {
        var url = $(this).find('.field--name-field-marquee-link a').attr('href');
        $(this).find('img').wrap('<a href="' + url + '"></a>');
      });
    },

    LinkMarqueeArticle: function () {
      $('.view-display-id-marquee_slideshow .node--type-article').each(function () {
        var url = $(this).find('h2 a').attr('href');
        $(this).wrap('<a href="' + url + '"></a>');
      });
    },

    MarqueeRandomizer: function () {
      var marquee = "url(/sites/default/files/marquee-bg/";
      $(".views_slideshow_cycle_slide").each(function (index, value) {
        var selector = Math.floor(Math.random() * 12 + 1);
        $(this).css({
          "background-image":
            "linear-gradient(0deg,rgba(0, 0, 0, 0) 55%, rgba(0, 0, 0, 0.4) 85%, rgba(0, 0, 0, 0.8) 100%), url(/sites/default/files/marquee-bg/" + selector + ".jpg",
          "background-size": "cover",
          "background-repeat": "no-repeat",
        });
      });
    },
    ArticleColumnCategoryHighlighting: function () {
      var isArticle = $("#page").hasClass("article");
      var isTermPage = $('');
      if (isArticle) {
        var type = $('.field--name-field-column-category a').html();
        //   $('.field--name-field-column-category').addClass(type);
      }
    },

    SearchRedirect: function () {
      $('#views-exposed-form-article-search-block-2 button').click(function (e) {

        setTimeout(function () {
          console.log("prevent");
          var results = $('.view-article-search .view-content.row').html();
          $('#block-palmdesert-page-title').remove();
          $('.articles-intro').html("MHCO Article Search Results");
          console.log(results);
          $('article').html(results);
        }, 1500);

      });
      $('#views-exposed-form-article-search-block-2 button').on('click', function () {
        if ($(window).width() < 992) { // Change 768 to your desired width
          $('html, body').animate({ scrollTop: 0 }, 'slow');
        }
      });

    },
    NavAnimate: function () {
      $('.navbar-nav > li.nav-item').mouseover(function () {
        $(this).find('ul').addClass('opened');
      });
      $('.navbar-nav > li.nav-item').mouseout(function () {
        $(this).find('ul').removeClass('opened');
      });
    },
    FormClick: function () {
      // Tell users to wait for download
      $('#F0 .form-button').html("-");
      $(".most-downloaded-forms, .form-download-div").off('click').on('click', function () {
        var formName = $(this).attr('title');
        var thisFormID = $(this).attr("id");
        var waitMessage = "Your form is being generated. Please wait several seconds for your form to load in a browser tab."
        $(this).find('.views-field-title').after('<br><div id="waitMessage" style="color: red">' + waitMessage + '</div>');
        setTimeout(function () {
          $('#waitMessage').remove();
        }, 5000);
      });

      $('.form-download-div').each(function (index, value) {
        var formID = $(this).find('.form-button.badge').attr("id");
        var formNID = $(this).find('.form-button.badge').attr("nid");
        var formDL = $(this).find('.form-button.badge').attr("dl");
        var formTitle = $(this).find('.form-button.badge').attr("title");
        //  console.log(formID + formNID + formDL + formTitle);
        $(this).attr("id", formID);
        $(this).attr("nid", formNID);
        $(this).attr("dl", formDL);
        $(this).attr("title", formTitle);
      })
    },

    TermBodyTrim: function () {
      var isTermPage = $("body").hasClass("page-vocabulary-column-topics");
      if (isTermPage) {
        $(".term-body").each(function (index, value) {
          var termtext = $(this).text();
          var termbody = $(this).text().length;
          var emptyParagraph = $(this).find("p").html().length;
          if (termbody < 116) {
            $(this).remove();

          }
          if (emptyParagraph < 46) {
            $(this).remove();
          }
        });
      }
    },
    RemoveAnonArticles: function () {
      var auth = $('body').hasClass("user-not-logged-in");
      var column = $('body').hasClass("node--type-column");
      var cu = $('body').hasClass("node--type-community-updates");

      if (auth && (column || cu)) {
        $('article').remove();
      }
    },
  };
})(jQuery, Drupal);