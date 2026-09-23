jQuery(function ($) {
  $(".smm-has-conflict").hover(
    function () {
      $(this).css("background-color", "#ffe69c");
    },
    function () {
      $(this).css("background-color", "#fff3cd");
    },
  );
});
