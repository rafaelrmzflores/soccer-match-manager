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
      wrap.find('input[type="hidden"][name$="_logo_id"]').val(attachment.id);
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

  $(document).on("click", ".smm-status-clear", function (e) {
    e.preventDefault();
    const line = $(this).closest(".smm-player-status-line");
    line.find('input[type="radio"]').prop("checked", false);
    line.data("user-touched", true);
  });

  $(document).on("click", ".smm-remove-logo", function (e) {
    e.preventDefault();
    const wrap = $(this).closest(".smm-logo-picker");
    wrap.find('input[type="hidden"][name$="_logo_id"]').val(0);
    wrap.find(".smm-logo-preview").empty();
    $(this).hide();
  });

  /* ============================================================
       MATCH FORM — league filter, my-teams sort, duration auto-fill
       ============================================================ */

  const form = $("#smm-match-form");
  if (form.length) {
    const myTeamIds = (form.data("my-team-ids") || "")
      .toString()
      .split(",")
      .filter(Boolean)
      .map(String);

    // Parse the primary-location map from the form's data attribute
    let teamPrimaryLocations = {};
    try {
      teamPrimaryLocations = JSON.parse(
        form.attr("data-team-primary-locations") || "{}",
      );
    } catch (e) {
      teamPrimaryLocations = {};
    }

    // Track the last auto-filled location so we only override when
    // the current value came from a previous auto-fill (not a user choice).
    let lastAutoLocation = null;

    // Set location to the home team's primary venue.
    function applyHomeVenue() {
      const homeId = $("#home_team_id").val();
      const currentLocation = $("#location_id").val();
      const primaryLoc =
        homeId && teamPrimaryLocations[homeId]
          ? String(teamPrimaryLocations[homeId])
          : "";

      if (!primaryLoc) {
        // No primary venue for this team — leave the location alone
        lastAutoLocation = null;
        return;
      }

      // Only override if the location is empty, or the current value
      // is exactly what we auto-filled last time.
      if (!currentLocation || currentLocation === lastAutoLocation) {
        $("#location_id").val(primaryLoc);
        lastAutoLocation = primaryLoc;
      }
    }

    // When the user manually picks a location, forget the auto-fill tracking
    $(document).on("change", "#location_id", function () {
      // If the change wasn't triggered by applyHomeVenue itself, clear the
      // auto-fill memory so future home-team changes don't override.
      if ($(this).val() !== lastAutoLocation) {
        lastAutoLocation = null;
      }
    });

    $(document).on("change", "#home_team_id", applyHomeVenue);

    // Run once on page load (only auto-fills if location is empty)
    applyHomeVenue();

    // Rebuild a <select>'s options from a list of teams.
    // "My teams" (in myTeamIds) sort first, then alphabetical.
    function buildTeamOptions(teams, selectedId) {
      const $select = $("<select></select>");
      const sorted = teams.slice().sort(function (a, b) {
        const aMine = myTeamIds.indexOf(String(a.id)) !== -1;
        const bMine = myTeamIds.indexOf(String(b.id)) !== -1;
        if (aMine !== bMine) return aMine ? -1 : 1;
        return a.name.toLowerCase().localeCompare(b.name.toLowerCase());
      });

      const $blank = $('<option value="">— Select —</option>');
      $select.append($blank);

      sorted.forEach(function (t) {
        const mine = myTeamIds.indexOf(String(t.id)) !== -1;
        const $opt = $("<option></option>")
          .attr("value", t.id)
          .attr("data-league", t.league_id)
          .attr("data-my-team", mine ? "1" : "0")
          .text((mine ? "★ " : "") + t.name);
        if (String(selectedId) === String(t.id)) $opt.prop("selected", true);
        $select.append($opt);
      });

      return $select.children();
    }

    // Rebuild a <select>'s options from a list of competitions.
    function buildCompOptions(comps, selectedId) {
      const $frag = $(document.createDocumentFragment());
      $frag.append('<option value="0" data-duration="">— None —</option>');
      comps.forEach(function (c) {
        const label =
          c.name +
          (c.season ? " (" + c.season + ")" : "") +
          (!c.league_id ? " — cross-league" : "");
        const $opt = $("<option></option>")
          .attr("value", c.id)
          .attr("data-duration", c.computed_duration || "")
          .attr("data-league", c.league_id)
          .text(label);
        if (String(selectedId) === String(c.id)) $opt.prop("selected", true);
        $frag.append($opt);
      });
      return $frag.children();
    }

    // Fetch teams for a league via AJAX and rebuild Home/Away dropdowns.
    function loadLeagueData(leagueId, selectedTeamId) {
      return $.post(SMM.ajax_url, {
        action: "smm_get_league_data",
        nonce: SMM.nonce,
        league_id: leagueId,
      }).done(function (res) {
        if (!res || !res.success) return;
        const teams = res.data.teams || [];

        $("#home_team_id")
          .empty()
          .append(buildTeamOptions(teams, selectedTeamId));
        $("#away_team_id")
          .empty()
          .append(buildTeamOptions(teams, selectedTeamId));

        refreshAutoPlayers();
      });
    }

    // Competition change: filter teams by the competition's league, and
    // auto-fill the duration from the option's data attribute.
    $("#competition_id").on("change", function () {
      const $opt = $(this).find("option:selected");
      const leagueId = parseInt($opt.data("league"), 10) || 0;
      const duration = parseInt($opt.data("duration"), 10) || 0;

      // Auto-fill duration if the option provides one
      if (duration > 0) {
        $("#match_duration").val(duration);
      }

      // Preserve current team selections if still valid
      const currentHome = $("#home_team_id").val();
      const currentAway = $("#away_team_id").val();

      loadLeagueData(leagueId, null, null).always(function () {
        // Restore selection if the team still exists in the new list
        if (
          currentHome &&
          $('#home_team_id option[value="' + currentHome + '"]').length
        ) {
          $("#home_team_id").val(currentHome);
        } else {
          $("#home_team_id").val("");
        }
        if (
          currentAway &&
          $('#away_team_id option[value="' + currentAway + '"]').length
        ) {
          $("#away_team_id").val(currentAway);
        } else {
          $("#away_team_id").val("");
        }
        refreshAutoPlayers();
      });
    });

    // Initial auto-fill on page load if a competition is already selected
    (function initDuration() {
      const dur = $("#competition_id").find("option:selected").data("duration");
      if (dur && dur > 0 && !$("#match_duration").val()) {
        $("#match_duration").val(dur);
      }
    })();

    // Time TBD toggle
    $("#time_tbd")
      .on("change", function () {
        const tbd = $(this).is(":checked");
        const $time = $("#match_time");
        if (tbd) {
          $time.data("prev-val", $time.val());
          $time.val("").prop("disabled", true);
        } else {
          $time.prop("disabled", false);
          const prev = $time.data("prev-val");
          if (prev) $time.val(prev);
        }
      })
      .trigger("change");
  }

  // Bulk-set all players to Going
  $(document).on("click", ".smm-set-all-going", function (e) {
    e.preventDefault();
    $('.smm-player-status-line input[value="going"]').prop("checked", true);
  });

  function refreshAutoPlayers() {
    const homeId = $("#home_team_id").val();
    const awayId = $("#away_team_id").val();
    const teamIds = [homeId, awayId].filter(Boolean).map(String);

    $(".smm-player-status-line").each(function () {
      const line = $(this);
      const playerTeam = String(line.data("team"));
      const isOnPlayingTeam = teamIds.indexOf(playerTeam) !== -1;

      const $going = line.find('input[value="going"]');
      const $maybe = line.find('input[value="maybe"]');
      const $notGoing = line.find('input[value="not_going"]');

      if (isOnPlayingTeam) {
        // Default to Going only if the user hasn't chosen anything else
        if (!$maybe.is(":checked") && !$notGoing.is(":checked")) {
          $going.prop("checked", true);
        }
        line.addClass("smm-auto-checked");
      } else {
        // Player is not on either team.
        // Clear the auto-selected Going, but respect an explicit user choice.
        if (line.data("user-touched")) {
          // Leave alone — user made a decision
        } else {
          // Was auto-set to Going and shouldn't be; clear it
          if (
            $going.is(":checked") &&
            !$maybe.is(":checked") &&
            !$notGoing.is(":checked")
          ) {
            $going.prop("checked", false);
          }
        }
        line.removeClass("smm-auto-checked");
      }
    });
  }

  // Mark lines the user has touched, so we never override their choice
  $(document).on(
    "change",
    '.smm-player-status-line input[type="radio"]',
    function () {
      $(this).closest(".smm-player-status-line").data("user-touched", true);
    },
  );
  $(document).on("change", ".smm-team-select", refreshAutoPlayers);

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

  /* ============================================================
    COMPETITION FORM — live computed duration
    ============================================================ */

  if ($("#smm-competition-form").length) {
    function computeDuration() {
      const periods = parseInt($("#periods").val(), 10) || 2;
      const periodMin = parseInt($("#period_minutes").val(), 10) || 0;
      const halftime = parseInt($("#halftime_minutes").val(), 10) || 0;
      const breakMin = parseInt($("#break_minutes").val(), 10) || 0;

      return periods * periodMin + halftime + periods * breakMin;
    }

    function refreshComputed() {
      $("#smm-computed-duration").text(computeDuration() + " minutes");
    }

    $(document).on(
      "change input",
      "#periods, #period_minutes, #halftime_minutes, #break_minutes",
      refreshComputed,
    );

    refreshComputed();
  }

  /* ============================================================
       TEAM FORM — venue rows
       ============================================================ */

  if ($("#smm-team-venues").length) {
    const $container = $("#smm-team-venues");

    // Add a new empty row
    $(document).on("click", "#smm-add-venue", function (e) {
      e.preventDefault();
      const idx = $container.find(".smm-venue-row").length;

      const $row = $('<div class="smm-venue-row"></div>');

      // Build the <select> by cloning options from an existing row
      const $firstSelect = $container.find(".smm-venue-select").first();
      if ($firstSelect.length) {
        const $clone = $firstSelect.clone().val("");
        $clone.attr("name", "venue[" + idx + "][location_id]");
        $row.append($clone);
      } else {
        $row.append(
          '<select name="venue[' +
            idx +
            '][location_id]" class="smm-venue-select"><option value="">— Select —</option></select>',
        );
      }

      $row.append(
        $('<label class="smm-venue-primary"></label>')
          .append($('<input type="radio" name="smm_venue_primary">').val(idx))
          .append(" Primary"),
      );

      $row.append(
        '<button type="button" class="button smm-venue-remove">Remove</button>',
      );

      $container.append($row);
    });

    // Remove a row
    $(document).on("click", ".smm-venue-remove", function (e) {
      e.preventDefault();
      $(this).closest(".smm-venue-row").remove();
    });
  }
});
