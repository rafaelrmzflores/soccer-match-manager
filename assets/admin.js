jQuery(function ($) {

    /* ---- Logo picker ---- */
    $(document).on('click', '.smm-upload-logo', function (e) {
        e.preventDefault();
        const wrap = $(this).closest('.smm-logo-picker');
        const frame = wp.media({
            title: 'Select Team Logo',
            button: { text: 'Use this logo' },
            multiple: false,
            library: { type: 'image' }
        });
        frame.on('select', function () {
            const attachment = frame.state().get('selection').first().toJSON();
            wrap.find('input[name="team_logo_id"]').val(attachment.id);
            const url = attachment.sizes && attachment.sizes.thumbnail
                ? attachment.sizes.thumbnail.url : attachment.url;
            let preview = wrap.find('.smm-logo-preview');
            if (!preview.length) {
                wrap.prepend('<div class="smm-logo-preview"></div>');
                preview = wrap.find('.smm-logo-preview');
            }
            preview.html('<img src="' + url + '" alt="">');
            wrap.find('.smm-remove-logo').show();
        });
        frame.open();
    });
    $(document).on('click', '.smm-remove-logo', function (e) {
        e.preventDefault();
        const wrap = $(this).closest('.smm-logo-picker');
        wrap.find('input[name="team_logo_id"]').val(0);
        wrap.find('.smm-logo-preview').empty();
        $(this).hide();
    });

    /* ============================================================
       MATCH FORM — league filter, my-teams sort, duration auto-fill
       ============================================================ */

    const form = $('#smm-match-form');
    if (form.length) {
        const myTeamIds = (form.data('my-team-ids') || '')
            .toString().split(',').filter(Boolean).map(String);

        // Rebuild a <select>'s options from a list of teams.
        // "My teams" (in myTeamIds) sort first, then alphabetical.
        function buildTeamOptions(teams, selectedId) {
            const $select = $('<select></select>');
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
                const $opt = $('<option></option>')
                    .attr('value', t.id)
                    .attr('data-league', t.league_id)
                    .attr('data-my-team', mine ? '1' : '0')
                    .text((mine ? '★ ' : '') + t.name);
                if (String(selectedId) === String(t.id)) $opt.prop('selected', true);
                $select.append($opt);
            });

            return $select.children();
        }

        // Rebuild a <select>'s options from a list of competitions.
        function buildCompOptions(comps, selectedId) {
            const $frag = $(document.createDocumentFragment());
            $frag.append('<option value="0" data-duration="">— None —</option>');
            comps.forEach(function (c) {
                const label = c.name + (c.season ? ' (' + c.season + ')' : '') +
                              (!c.league_id ? ' — cross-league' : '');
                const $opt = $('<option></option>')
                    .attr('value', c.id)
                    .attr('data-duration', c.computed_duration || '')
                    .attr('data-league', c.league_id)
                    .text(label);
                if (String(selectedId) === String(c.id)) $opt.prop('selected', true);
                $frag.append($opt);
            });
            return $frag.children();
        }

        // Fetch teams + competitions for a league via AJAX.
        function loadLeagueData(leagueId, selectedTeamId, selectedCompId) {
            return $.post(SMM.ajax_url, {
                action: 'smm_get_league_data',
                nonce: SMM.nonce,
                league_id: leagueId
            }).done(function (res) {
                if (!res || !res.success) return;
                const teams = res.data.teams || [];
                const comps = res.data.competitions || [];

                $('#home_team_id').empty().append(buildTeamOptions(teams, selectedTeamId));
                $('#away_team_id').empty().append(buildTeamOptions(teams, selectedTeamId));
                $('#competition_id').empty().append(buildCompOptions(comps, selectedCompId));

                refreshAutoPlayers();
            });
        }

        // League change: reload teams and competitions, keep existing selection if possible.
        $('#match_league_id').on('change', function () {
            const leagueId = $(this).val();
            const currentHome = $('#home_team_id').val();
            const currentAway = $('#away_team_id').val();
            const currentComp = $('#competition_id').val();
            loadLeagueData(leagueId, currentHome, currentComp)
                .always(function () {
                    // Attempt to keep the previous selection if it still exists
                    if ($('#home_team_id option[value="' + currentHome + '"]').length) {
                        $('#home_team_id').val(currentHome);
                    } else {
                        $('#home_team_id').val('');
                    }
                    if ($('#away_team_id option[value="' + currentAway + '"]').length) {
                        $('#away_team_id').val(currentAway);
                    } else {
                        $('#away_team_id').val('');
                    }
                });
        });

        // Competition change: auto-fill duration from data attribute.
        $('#competition_id').on('change', function () {
            const dur = $(this).find('option:selected').data('duration');
            if (dur && dur > 0) {
                $('#match_duration').val(dur);
            }
        });

        // Initial auto-fill on page load if a competition is already selected
        (function initDuration() {
            const dur = $('#competition_id').find('option:selected').data('duration');
            if (dur && dur > 0 && !$('#match_duration').val()) {
                $('#match_duration').val(dur);
            }
        })();

        // Time TBD toggle
        $('#time_tbd').on('change', function () {
            const tbd = $(this).is(':checked');
            const $time = $('#match_time');
            if (tbd) {
                $time.data('prev-val', $time.val());
                $time.val('').prop('disabled', true);
            } else {
                $time.prop('disabled', false);
                const prev = $time.data('prev-val');
                if (prev) $time.val(prev);
            }
        }).trigger('change');
    }

    /* ---- Auto-check players based on selected teams ---- */
    function refreshAutoPlayers() {
        const homeId = $('#home_team_id').val();
        const awayId = $('#away_team_id').val();
        const teamIds = [homeId, awayId].filter(Boolean).map(String);
        $('.smm-player-line').each(function () {
            const line = $(this);
            const checkbox = line.find('input[type="checkbox"]');
            const playerTeam = String(checkbox.data('team'));
            const matchesTeam = teamIds.indexOf(playerTeam) !== -1;
            if (matchesTeam) {
                if (!checkbox.data('user-touched')) checkbox.prop('checked', true);
                line.addClass('smm-auto-checked');
            } else {
                line.removeClass('smm-auto-checked');
            }
        });
    }
    $(document).on('change', '.smm-player-line input[type="checkbox"]', function () {
        $(this).data('user-touched', true);
    });
    $(document).on('change', '.smm-team-select', refreshAutoPlayers);

    /* ---- Check-all checkbox ---- */
    $(document).on('change', '#smm-check-all', function () {
        $('input[name="match_ids[]"]').prop('checked', $(this).is(':checked'));
    });

    /* ---- Conflict hover ---- */
    $('.smm-has-conflict').hover(
        function () { $(this).css('background-color', '#ffe69c'); },
        function () { $(this).css('background-color', '#fff3cd'); }
    );

    /* ============================================================
       COMPETITION FORM — show/hide fields based on periods + live total
       ============================================================ */

    if ($('#smm-competition-form').length) {
        function updateFormatFields() {
            const periods = parseInt($('#periods').val(), 10) || 2;
            const isHalves = (periods === 2);
            const isQuarters = (periods === 4);

            $('.smm-field-halves').toggle(isHalves);
            $('.smm-field-quarters').toggle(isQuarters);
        }

        function computeDuration() {
            const periods    = parseInt($('#periods').val(), 10) || 2;
            const periodMin  = parseInt($('#period_minutes').val(), 10) || 0;
            const halftime   = parseInt($('#halftime_minutes').val(), 10) || 0;
            const waterBreak = parseInt($('#water_break_minutes').val(), 10) || 0;
            const breakMin   = parseInt($('#break_minutes').val(), 10) || 0;

            let total;
            if (periods === 2) {
                total = (2 * periodMin) + halftime + (2 * waterBreak);
            } else {
                const shortBreaks = Math.max(0, periods - 2);
                total = (periods * periodMin) + (shortBreaks * breakMin) + halftime;
            }
            return total;
        }

        function refreshComputed() {
            const total = computeDuration();
            $('#smm-computed-duration').text(total + ' minutes');
        }

        $(document).on('change input', '#periods, #period_minutes, #halftime_minutes, #water_break_minutes, #break_minutes', function () {
            updateFormatFields();
            refreshComputed();
        });

        updateFormatFields();
        refreshComputed();
    }

});