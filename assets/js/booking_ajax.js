/* ============================================================
   CampusOrbit — booking_ajax.js
   Asynchronous calendar slot inspection for venue bookings.
   ============================================================ */

(function () {
    'use strict';

    /**
     * Build calendar matrix for a given month.
     * Each cell records:
     *  - day (number or null for empty)
     *  - state: 'available' | 'pending' | 'confirmed' | 'empty' | 'past'
     *  - ymd: YYYY-MM-DD string
     */
    function buildCalendar(year, month, availability, selectedYmd) {
        const firstDay  = new Date(year, month - 1, 1);
        const lastDay   = new Date(year, month, 0);
        const startWeekday = firstDay.getDay(); // 0 = Sun
        const daysInMonth = lastDay.getDate();
        const today = new Date();
        today.setHours(0,0,0,0);

        const grid = document.createElement('div');
        grid.className = 'calendar-grid';

        const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        dayNames.forEach(n => {
            const el = document.createElement('div');
            el.className = 'calendar-day-name';
            el.textContent = n;
            grid.appendChild(el);
        });

        // Empty cells before month start
        for (let i = 0; i < startWeekday; i++) {
            const cell = document.createElement('div');
            cell.className = 'calendar-cell empty';
            grid.appendChild(cell);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const cellDate = new Date(year, month - 1, d);
            cellDate.setHours(0,0,0,0);
            // Build YYYY-MM-DD from LOCAL components (toISOString() uses UTC and can
            // shift dates backward in positive-UTC-offset timezones).
            const ymd = `${cellDate.getFullYear()}-${String(cellDate.getMonth() + 1).padStart(2, '0')}-${String(cellDate.getDate()).padStart(2, '0')}`;

            const cell = document.createElement('div');
            cell.className = 'calendar-cell';
            cell.textContent = d;

            if (cellDate.getTime() === today.getTime()) {
                cell.classList.add('today');
            }

            if (cellDate.getTime() < today.getTime()) {
                cell.classList.add('disabled', 'past');
            } else if (availability[ymd] === 'confirmed') {
                cell.classList.add('confirmed');
            } else if (availability[ymd] === 'pending') {
                cell.classList.add('pending');
            } else {
                cell.classList.add('available');
            }

            if (selectedYmd === ymd) {
                cell.classList.add('selected');
            }

            cell.dataset.ymd = ymd;
            cell.addEventListener('click', () => onDateSelected(ymd, cell));
            grid.appendChild(cell);
        }

        return grid;
    }

    /**
     * Map of venue_id -> { 'YYYY-MM-DD': 'pending'|'confirmed' }
     * Used to color calendar cells. Built by fetching /actions/fetch_calendar.php.
     */
    let calendarData = {};
    let currentMonth = (new Date()).getMonth() + 1;
    let currentYear  = (new Date()).getFullYear();
    let selectedDate = null;

    const calendarEl   = document.getElementById('calendar');
    const scheduleEl   = document.getElementById('schedule-panel');
    const monthLabelEl = document.getElementById('calendar-month-label');
    const venueSelect  = document.getElementById('venue_id');
    const dateInput    = document.getElementById('event_date');
    const prevBtn      = document.getElementById('prev-month');
    const nextBtn      = document.getElementById('next-month');

    function renderCalendar () {
        calendarEl.innerHTML = '';
        const grid = buildCalendar(currentYear, currentMonth, calendarData, selectedDate);
        calendarEl.appendChild(grid);
        monthLabelEl.textContent =
            new Date(currentYear, currentMonth - 1, 1)
                  .toLocaleString('default', { month: 'long', year: 'numeric' });
    }

    function fetchCalendar(venueId, year, month) {
        if (!venueId) {
            calendarData = {};
            renderCalendar();
            return;
        }
        fetch(`/actions/fetch_calendar.php?venue_id=${encodeURIComponent(venueId)}&year=${year}&month=${month}`)
            .then(r => r.json())
            .then(json => {
                if (!json.success) {
                    showScheduleError(json.message || 'Failed to load calendar.');
                    return;
                }
                calendarData = json.data || {};
                renderCalendar();
            })
            .catch(() => {
                showScheduleError('Network error. Could not load calendar.');
            });
    }

    function onDateSelected(ymd, cellEl) {
        if (!venueSelect.value) {
            alert('Please choose a venue first.');
            return;
        }
        if (cellEl.classList.contains('disabled') || cellEl.classList.contains('past')) {
            return;
        }
        selectedDate = ymd;
        dateInput.value = ymd;
        renderCalendar();
        fetchSlots(ymd);
    }

    function fetchSlots(ymd) {
        if (!venueSelect.value || !ymd) {
            scheduleEl.innerHTML = emptySchedule('Pick a venue and date to see the schedule.');
            return;
        }
        scheduleEl.innerHTML = '<div class="state-block"><div class="state-icon"><span class="loading-dots"></span></div>Loading schedule</div>';
        const url = `/actions/fetch_slots.php?venue_id=${encodeURIComponent(venueSelect.value)}&date=${encodeURIComponent(ymd)}`;
        fetch(url)
            .then(r => r.json())
            .then(json => {
                if (!json.success) {
                    showScheduleError(json.message || 'Could not load schedule.');
                    return;
                }
                renderSlots(json.data || []);
            })
            .catch(() => {
                showScheduleError('Network error. Please try again.');
            });
    }

    function renderSlots(slots) {
        if (!slots.length) {
            scheduleEl.innerHTML = emptySchedule('No bookings on this date — the slot is free!');
            return;
        }
        const html = slots.map(s => `
            <div class="schedule-slot">
                <div class="slot-title">${escapeHtml(s.club_name)}</div>
                <div class="slot-meta">
                    <i class="bi bi-geo-alt"></i>${escapeHtml(s.venue_name)}<br>
                    <i class="bi bi-clock"></i>${escapeHtml(format12h(s.start_time))} – ${escapeHtml(format12h(s.end_time))}
                </div>
                <div class="mt-2"><span class="status-badge ${escapeHtml(s.status)}">${escapeHtml(s.status)}</span></div>
            </div>
        `).join('');
        scheduleEl.innerHTML = html;
    }

    /**
     * Convert "HH:MM" or "HH:MM:SS" (24h) -> "h:MM AM/PM" (12h).
     * Returns the original string if it can't parse.
     */
    function format12h(hhmm) {
        if (!hhmm) return '';
        const m = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec(String(hhmm));
        if (!m) return hhmm;
        let h   = parseInt(m[1], 10);
        const min = m[2];
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12;
        if (h === 0) h = 12;
        return `${h}:${min} ${ampm}`;
    }

    function showScheduleError(message) {
        scheduleEl.innerHTML = `
            <div class="state-block">
                <div class="state-icon"><i class="bi bi-exclamation-triangle"></i></div>
                <div>${escapeHtml(message)}</div>
            </div>
        `;
    }

    function emptySchedule(message) {
        return `
            <div class="state-block">
                <div class="state-icon"><i class="bi bi-calendar-x"></i></div>
                <div>${escapeHtml(message)}</div>
            </div>
        `;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    /* ---- Initial wiring ---- */
    if (calendarEl) {
        if (venueSelect) {
            venueSelect.addEventListener('change', () => {
                selectedDate = null;
                dateInput.value = '';
                scheduleEl.innerHTML = emptySchedule('Select a date on the calendar to see its schedule.');
                fetchCalendar(venueSelect.value, currentYear, currentMonth);
            });
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                currentMonth--;
                if (currentMonth < 1) { currentMonth = 12; currentYear--; }
                if (venueSelect.value) fetchCalendar(venueSelect.value, currentYear, currentMonth);
                renderCalendar();
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                currentMonth++;
                if (currentMonth > 12) { currentMonth = 1; currentYear++; }
                if (venueSelect.value) fetchCalendar(venueSelect.value, currentYear, currentMonth);
                renderCalendar();
            });
        }

        // Initial render
        renderCalendar();
        if (venueSelect && venueSelect.value) {
            fetchCalendar(venueSelect.value, currentYear, currentMonth);
        }
    }

    /* ---- RSVP / Join Club helpers exposed globally ---- */
    window.CampusOrbit = window.CampusOrbit || {};

    window.CampusOrbit.rsvp = function (eventId, btn) {
        if (!eventId) return;
        btn.disabled = true;
        btn.innerHTML = '<span class="loading-dots"></span>';
        const csrf = btn.dataset.csrf || '';
        fetch('/actions/event_handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf
            },
            body: new URLSearchParams({ action: 'rsvp', event_id: eventId, csrf_token: csrf })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) {
                if (btn.classList.contains('card-btn-attend')) {
                    btn.classList.remove('card-btn-attend');
                    btn.classList.add('card-btn-attending');
                } else {
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-soft');
                }
                btn.innerHTML = '<i class="bi bi-check2-circle"></i> Attending';
                // Switch onclick to cancelRsvp
                btn.setAttribute('onclick', `CampusOrbit.cancelRsvp(${eventId}, this)`);
                btn.disabled = false;
                
                if (window.CampusOrbit && CampusOrbit.toast) {
                    CampusOrbit.toast({ success: true, message: json.message || 'You are now attending!' });
                }
            } else if (json.require_join && json.club_id) {
                // Show join modal
                const m = new bootstrap.Modal(document.getElementById('joinClubModal'));
                document.getElementById('join-club-name').textContent = json.club_name || '';
                document.getElementById('join-club-btn').dataset.clubId = json.club_id;
                document.getElementById('join-club-btn').dataset.csrf = csrf;
                m.show();
                btn.disabled = false;
                btn.textContent = 'RSVP';
            } else {
                if (window.CampusOrbit && CampusOrbit.toast) {
                    CampusOrbit.toast({ success: false, message: json.message || 'Could not RSVP.' });
                } else {
                    alert(json.message || 'Could not RSVP.');
                }
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-calendar-plus"></i> Attend';
            }
        })
        .catch(() => {
            if (window.CampusOrbit && CampusOrbit.toast) {
                CampusOrbit.toast({ success: false, message: 'Network error.' });
            } else {
                alert('Network error.');
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-calendar-plus"></i> Attend';
        });
    };

    window.CampusOrbit.cancelRsvp = function (eventId, btn) {
        if (!eventId) return;
        btn.disabled = true;
        btn.innerHTML = '<span class="loading-dots"></span>';
        const csrf = btn.dataset.csrf || '';
        fetch('/actions/event_handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf
            },
            body: new URLSearchParams({ action: 'cancel_rsvp', event_id: eventId, csrf_token: csrf })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) {
                if (btn.classList.contains('card-btn-attending')) {
                    btn.classList.remove('card-btn-attending');
                    btn.classList.add('card-btn-attend');
                } else {
                    btn.classList.remove('btn-soft');
                    btn.classList.add('btn-primary');
                }
                btn.innerHTML = '<i class="bi bi-calendar-plus"></i> Attend';
                // Switch onclick to rsvp
                btn.setAttribute('onclick', `CampusOrbit.rsvp(${eventId}, this)`);
                btn.disabled = false;
                
                if (window.CampusOrbit && CampusOrbit.toast) {
                    CampusOrbit.toast({ success: true, message: json.message || 'RSVP cancelled.' });
                }
            } else {
                if (window.CampusOrbit && CampusOrbit.toast) {
                    CampusOrbit.toast({ success: false, message: json.message || 'Could not cancel RSVP.' });
                } else {
                    alert(json.message || 'Could not cancel RSVP.');
                }
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check2-circle"></i> Attending';
            }
        })
        .catch(() => {
            if (window.CampusOrbit && CampusOrbit.toast) {
                CampusOrbit.toast({ success: false, message: 'Network error.' });
            } else {
                alert('Network error.');
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check2-circle"></i> Attending';
        });
    };

    window.CampusOrbit.joinClub = function (btn) {
        const clubId = btn.dataset.clubId;
        const csrf   = btn.dataset.csrf || '';
        if (!clubId) return;
        btn.disabled = true;
        btn.textContent = 'Joining...';
        fetch('/actions/club_handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf
            },
            body: new URLSearchParams({ action: 'join_club', club_id: clubId, csrf_token: csrf })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) {
                btn.textContent = 'Joined! Reload to RSVP now.';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-soft');
                if (window.CampusOrbit && CampusOrbit.toast) {
                    CampusOrbit.toast({ success: true, message: json.message || 'Welcome to the club!' });
                }
            } else {
                btn.disabled = false;
                btn.textContent = 'Join Club';
                if (window.CampusOrbit && CampusOrbit.toast) {
                    CampusOrbit.toast({ success: false, message: json.message || 'Could not join club.' });
                } else {
                    alert(json.message || 'Could not join club.');
                }
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = 'Join Club';
            if (window.CampusOrbit && CampusOrbit.toast) {
                CampusOrbit.toast({ success: false, message: 'Network error.' });
            } else {
                alert('Network error.');
            }
        });
    };

})();
