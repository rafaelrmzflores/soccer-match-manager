jQuery(function ($) {
  /* ---- Logo picker ---- */
  $(document).on("click", ".smm-upload-logo", function (e) {
    e.preventDefault();
    const wrap = $(this).closest(".smm-logo-picker");
    const frame = wp.media({
      title: "Select Team Logo",
      button: { text: "Use this logo" },
      multiple: false,
      library: { type: "image" },
    });
    frame.on("select", function () {
      const attachment = frame.state().get("selection").first().toJSON();
      wrap.find('input[name="team_logo_id"]').val(attachment.id);
      const url =
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
  $(document).on("click", ".smm-remove-logo", function (e) {
    e.preventDefault();
    const wrap = $(this).closest(".smm-logo-picker");
    wrap.find('input[name="team_logo_id"]').val(0);
    wrap.find(".smm-logo-preview").empty();
    $(this).hide();
  });

  /* ---- Auto-check players based on selected teams ---- */
  function refreshAutoPlayers() {
    const homeId = $("#home_team_id").val();
    const awayId = $("#away_team_id").val();
    const teamIds = [homeId, awayId].filter(Boolean).map(String);
    $(".smm-player-line").each(function () {
      const line = $(this);
      const checkbox = line.find('input[type="checkbox"]');
      const playerTeam = String(checkbox.data("team"));
      const matchesTeam = teamIds.indexOf(playerTeam) !== -1;
      if (matchesTeam) {
        if (!checkbox.data("user-touched")) checkbox.prop("checked", true);
        line.addClass("smm-auto-checked");
      } else {
        line.removeClass("smm-auto-checked");
      }
    });
  }
  $(document).on(
    "change",
    '.smm-player-line input[type="checkbox"]',
    function () {
      $(this).data("user-touched", true);
    },
  );
  $(document).on("change", ".smm-team-select", refreshAutoPlayers);
  if ($("#smm-match-form").length) refreshAutoPlayers();

  /* ---- Check-all checkbox ---- */
  $(document).on("change", "#smm-check-all", function () {
    $('input[name="match_ids[]"]').prop("checked", $(this).is(":checked"));
  });

  /* ---- Conflict hover ---- */
  $(".smm-has-conflict").hover(
    function () {
      $(this).css("background-color", "#ffe69c");
    },
    function () {
      $(this).css("background-color", "#fff3cd");
    },
  );
});
