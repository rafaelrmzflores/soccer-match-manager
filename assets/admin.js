jQuery(function ($) {
  /* ---- Logo picker (WP media uploader) ---- */
  $(document).on("click", ".smm-upload-logo", function (e) {
    e.preventDefault();
    const button = $(this);
    const wrap = button.closest(".smm-logo-picker");

    const frame = wp.media({
      title: "Select Team Logo",
      button: { text: "Use this logo" },
      multiple: false,
      library: { type: "image" },
    });

    frame.on("select", function () {
      const attachment = frame.state().get("selection").first().toJSON();
      wrap.find('input[name="team_logo_id"]').val(attachment.id);

      let url =
        attachment.sizes && attachment.sizes.thumbnail
          ? attachment.sizes.thumbnail.url
          : attachment.url;

      let preview = wrap.find(".smm-logo-preview");
      if (!preview.length) {
        wrap.prepend('<div class="smm-logo-preview"></div>');
        preview = wrap.find(".smm-logo-preview");
      }
      preview.html('<img src="' + url + '" alt="">');
      wrap.find(".smm-remove-logo").show();
    });

    frame.open();
  });

  /* ---- Remove logo ---- */
  $(document).on("click", ".smm-remove-logo", function (e) {
    e.preventDefault();
    const wrap = $(this).closest(".smm-logo-picker");
    wrap.find('input[name="team_logo_id"]').val(0);
    wrap.find(".smm-logo-preview").empty();
    $(this).hide();
  });

  /* ---- Hover on conflict rows ---- */
  $(".smm-has-conflict").hover(
    function () {
      $(this).css("background-color", "#ffe69c");
    },
    function () {
      $(this).css("background-color", "#fff3cd");
    },
  );
});
