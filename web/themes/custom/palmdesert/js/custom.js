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
          $(this).remove();
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
        var selector = Math.floor(Math.random() * 17 + 1);
        $(this).css({
          "background-image":
            "linear-gradient(0deg,rgba(0, 0, 0, 0) 55%, rgba(0, 0, 0, 0.4) 85%, rgba(0, 0, 0, 0.8) 100%), url(/sites/default/files/marquee-bg/" + selector + ".jpg",
          "background-size": "cover",
          "background-repeat": "no-repeat",
        });
      });;
    },
    ArticleColumnCategoryHighlighting: function () {
      var isArticle = $("#page").hasClass("article");
      var isTermPage = $('');
      if (isArticle) {
        var type = $('.field--name-field-column-category a').html();
        $('.field--name-field-column-category').addClass(type);
      }
    },

    SearchRedirect: function () {
      $('#views-exposed-form-article-search-block-2 button').click(function (e) {

        setTimeout(function () {
          console.log("prevent");
          var results = $('.view-article-search .view-content.row').html();
          $('#block-palmdesert-page-title').remove();
          $('.articles-intro').html("MHCO Article Search Results");
          $('#block-palmdesert-content').html(results);
        }, 1100);

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
      var isForm = $('body').hasClass('page-view-mhco-forms');

      if (isForm) {
        // Tell users to wait for download

        $(".form-download-div").off('click').on('click', function() {
          var formName = $(this).attr('title');
          var thisFormID = $(this).attr("id");
          var waitMessage = "Your form is being generated. Please wait several seconds for your form to load in a browser tab."
          $(this).find('.views-field-title').after('<br><div id="waitMessage" style="color: red">' + waitMessage + '</div>');
          setTimeout(function () {
            $('#waitMessage').remove();
          }, 5000);
        });
      }


      if (isForm) {
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


      }
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
  };
})(jQuery, Drupal);