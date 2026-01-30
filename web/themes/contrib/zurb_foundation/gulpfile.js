// This build process is based off of the basic starter project
// for Foundation for Sites 6.
// https://github.com/foundation/foundation-sites-template

var gulp          = require('gulp');
var browserSync   = require('browser-sync').create();
var $             = require('gulp-load-plugins')();
var autoprefixer  = require('autoprefixer');

var sassPaths = [
  'node_modules/foundation-sites/scss',
  'node_modules/motion-ui/src'
];

// Build CSS files.
function sass() {
  return gulp.src('scss/zurb_foundation.scss')
    .pipe($.sass({
      includePaths: sassPaths,
      outputStyle: 'compressed' // if css compressed **file size**
    })
      .on('error', $.sass.logError))
    .pipe($.postcss([
      autoprefixer()
    ]))
    .pipe(gulp.dest('css'))
    .pipe(browserSync.stream());
};

// Copy files from Foundation node modules.
function copy() {
  return gulp.src(
    'node_modules/foundation-sites/dist/css/*.css',
    'node_modules/motion-ui/dist/*.css')
    .pipe(gulp.dest('css')),
    gulp.src(
    'node_modules/foundation-sites/dist/js/*.js',
    'node_modules/motion-ui/dist/*.js')
    .pipe(gulp.dest('js'));
};

// WIP: BrowserSync.
function serve() {
  browserSync.init({
    server: "./"
  });

  gulp.watch("scss/*.scss", sass);
  gulp.watch("*.html").on('change', browserSync.reload);
}

// Watch for changes in scss files.
function watch() {
  gulp.watch(['scss/**/*.scss'], sass)
};

// Gulp tasks:
gulp.task('copy', copy);
gulp.task('sass', gulp.series('copy', sass));
gulp.task('default', gulp.series(sass, copy, watch));
