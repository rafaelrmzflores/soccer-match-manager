jQuery(document).ready(function ($) {
  // Add confirmation for delete actions
  $(".smm-delete-match").on("click", function (e) {
    if (!confirm("Are you sure you want to delete this match?")) {
      e.preventDefault();
    }
  });

  // Highlight conflict rows on hover
  $(".smm-has-conflict").hover(
    function () {
      $(this).css("background-color", "#ffe69c");
    },
    function () {
      $(this).css("background-color", "#fff3cd");
    },
  );
});
