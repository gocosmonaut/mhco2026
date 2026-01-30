/******/ (function() { // webpackBootstrap
/******/ 	var __webpack_modules__ = ({

/***/ "./node_modules/motion-ui/dist/motion-ui.js":
/*!**************************************************!*\
  !*** ./node_modules/motion-ui/dist/motion-ui.js ***!
  \**************************************************/
/***/ (function(module, exports, __webpack_require__) {

var __WEBPACK_AMD_DEFINE_FACTORY__, __WEBPACK_AMD_DEFINE_ARRAY__, __WEBPACK_AMD_DEFINE_RESULT__;function _typeof(obj) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (obj) { return typeof obj; } : function (obj) { return obj && "function" == typeof Symbol && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }, _typeof(obj); }

;

(function (root, factory) {
  if (true) {
    !(__WEBPACK_AMD_DEFINE_ARRAY__ = [__webpack_require__(/*! jquery */ "jquery")], __WEBPACK_AMD_DEFINE_FACTORY__ = (factory),
		__WEBPACK_AMD_DEFINE_RESULT__ = (typeof __WEBPACK_AMD_DEFINE_FACTORY__ === 'function' ?
		(__WEBPACK_AMD_DEFINE_FACTORY__.apply(exports, __WEBPACK_AMD_DEFINE_ARRAY__)) : __WEBPACK_AMD_DEFINE_FACTORY__),
		__WEBPACK_AMD_DEFINE_RESULT__ !== undefined && (module.exports = __WEBPACK_AMD_DEFINE_RESULT__));
  } else {}
})(this, function ($) {
  'use strict'; // Polyfill for requestAnimationFrame

  (function () {
    if (!Date.now) Date.now = function () {
      return new Date().getTime();
    };
    var vendors = ['webkit', 'moz'];

    for (var i = 0; i < vendors.length && !window.requestAnimationFrame; ++i) {
      var vp = vendors[i];
      window.requestAnimationFrame = window[vp + 'RequestAnimationFrame'];
      window.cancelAnimationFrame = window[vp + 'CancelAnimationFrame'] || window[vp + 'CancelRequestAnimationFrame'];
    }

    if (/iP(ad|hone|od).*OS 6/.test(window.navigator.userAgent) || !window.requestAnimationFrame || !window.cancelAnimationFrame) {
      var lastTime = 0;

      window.requestAnimationFrame = function (callback) {
        var now = Date.now();
        var nextTime = Math.max(lastTime + 16, now);
        return setTimeout(function () {
          callback(lastTime = nextTime);
        }, nextTime - now);
      };

      window.cancelAnimationFrame = clearTimeout;
    }
  })();

  var initClasses = ['mui-enter', 'mui-leave'];
  var activeClasses = ['mui-enter-active', 'mui-leave-active']; // Find the right "transitionend" event for this browser

  var endEvent = function () {
    var transitions = {
      'transition': 'transitionend',
      'WebkitTransition': 'webkitTransitionEnd',
      'MozTransition': 'transitionend',
      'OTransition': 'otransitionend'
    };
    var elem = window.document.createElement('div');

    for (var t in transitions) {
      if (typeof elem.style[t] !== 'undefined') {
        return transitions[t];
      }
    }

    return null;
  }();

  function animate(isIn, element, animation, cb) {
    element = $(element).eq(0);
    if (!element.length) return;

    if (endEvent === null) {
      isIn ? element.show() : element.hide();
      cb();
      return;
    }

    var initClass = isIn ? initClasses[0] : initClasses[1];
    var activeClass = isIn ? activeClasses[0] : activeClasses[1]; // Set up the animation

    reset();
    element.addClass(animation);
    element.css('transition', 'none');
    requestAnimationFrame(function () {
      element.addClass(initClass);
      if (isIn) element.show();
    }); // Start the animation

    requestAnimationFrame(function () {
      element[0].offsetWidth;
      element.css('transition', '');
      element.addClass(activeClass);
    }); // Clean up the animation when it finishes

    element.one('transitionend', finish); // Hides the element (for out animations), resets the element, and runs a callback

    function finish() {
      if (!isIn) element.hide();
      reset();
      if (cb) cb.apply(element);
    } // Resets transitions and removes motion-specific classes


    function reset() {
      element[0].style.transitionDuration = 0;
      element.removeClass(initClass + ' ' + activeClass + ' ' + animation);
    }
  }

  var MotionUI = {
    animateIn: function animateIn(element, animation, cb) {
      animate(true, element, animation, cb);
    },
    animateOut: function animateOut(element, animation, cb) {
      animate(false, element, animation, cb);
    }
  };
  return MotionUI;
});

/***/ }),

/***/ "jquery":
/*!*************************!*\
  !*** external "jQuery" ***!
  \*************************/
/***/ (function(module) {

"use strict";
module.exports = jQuery;

/***/ })

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		__webpack_modules__[moduleId].call(module.exports, module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	
/******/ 	// startup
/******/ 	// Load entry module and return exports
/******/ 	// This entry module is referenced by other modules so it can't be inlined
/******/ 	var __webpack_exports__ = __webpack_require__("./node_modules/motion-ui/dist/motion-ui.js");
/******/ 	
/******/ })()
;
//# sourceMappingURL=motion-ui.js.map