'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const shell = document.querySelector('[data-app-shell]');
  const sidebar = document.getElementById('portal-sidebar');
  const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
  const mobileSidebarToggle = document.querySelector('.sidebar-mobile-toggle');
  const desktopSidebar = window.matchMedia('(min-width: 992px)');
  const sidebarPreferenceKey = shell?.dataset.sidebarKey || 'wls-sidebar-collapsed';
  const setSidebarPinned = (pinned, persist = false) => {
    if (!shell || !sidebarToggle) return;
    shell.classList.toggle('sidebar-pinned', pinned);
    shell.classList.toggle('sidebar-collapsed', desktopSidebar.matches && !pinned);
    sidebarToggle.setAttribute('aria-pressed', String(pinned));
    sidebarToggle.setAttribute('aria-label', pinned ? 'Allow navigation to hide' : 'Keep navigation open');
    sidebarToggle.setAttribute('title', pinned ? 'Allow navigation to hide' : 'Keep navigation open');
    sidebarToggle.innerHTML = pinned
      ? '<i class="bi bi-layout-sidebar-inset" aria-hidden="true"></i>'
      : '<i class="bi bi-layout-sidebar" aria-hidden="true"></i>';
    if (persist) {
      window.localStorage.setItem(sidebarPreferenceKey, String(pinned));
    }
  };

  if (shell && sidebar && sidebarToggle) {
    const storedSidebarPreference = window.localStorage.getItem(sidebarPreferenceKey) === 'true';
    setSidebarPinned(desktopSidebar.matches && storedSidebarPreference);

    sidebar.addEventListener('show.bs.offcanvas', () => {
      if (mobileSidebarToggle) mobileSidebarToggle.setAttribute('aria-expanded', 'true');
    });
    sidebar.addEventListener('hidden.bs.offcanvas', () => {
      if (mobileSidebarToggle) mobileSidebarToggle.setAttribute('aria-expanded', 'false');
    });

    sidebarToggle.addEventListener('click', () => {
      setSidebarPinned(!shell.classList.contains('sidebar-pinned'), true);
    });

    sidebar.addEventListener('pointerenter', () => {
      if (desktopSidebar.matches && shell.classList.contains('sidebar-collapsed')) {
        sidebar.classList.add('sidebar-hover-open');
      }
    });
    sidebar.addEventListener('pointerleave', () => {
      sidebar.classList.remove('sidebar-hover-open');
    });
    sidebar.addEventListener('focusin', () => {
      if (desktopSidebar.matches && shell.classList.contains('sidebar-collapsed')) {
        sidebar.classList.add('sidebar-hover-open');
      }
    });
    sidebar.addEventListener('focusout', (event) => {
      if (!sidebar.contains(event.relatedTarget)) sidebar.classList.remove('sidebar-hover-open');
    });

    desktopSidebar.addEventListener('change', (event) => {
      const pinned = event.matches && window.localStorage.getItem(sidebarPreferenceKey) === 'true';
      setSidebarPinned(pinned);
      sidebar.classList.remove('sidebar-hover-open');
    });
  }

  document.querySelectorAll('.account-create-form').forEach((form) => {
    const role = form.querySelector('#role');
    const username = form.querySelector('[data-account-username]');
    const usernameHelp = form.querySelector('[data-account-username-help]');
    const studentFields = form.querySelectorAll('[data-student-only]');
    if (!role || !username) return;

    const updateStudentUsername = () => {
      const isStudent = role.value === 'student';
      username.required = !isStudent;
      username.disabled = isStudent;
      username.placeholder = isStudent ? 'Generated automatically' : 'Enter username';
      studentFields.forEach((field) => { field.hidden = !isStudent; });
      if (usernameHelp) {
        usernameHelp.textContent = isStudent
          ? 'Student ID and login username are generated from the admission school year.'
          : 'For students, the ID and username are generated automatically.';
      }
    };

    role.addEventListener('change', updateStudentUsername);
    updateStudentUsername();
  });

  const overviewContext = document.querySelector('[data-overview-context]');
  if (overviewContext) {
    const clock = overviewContext.querySelector('[data-live-clock]');
    const updateClock = () => {
      if (clock) {
        clock.textContent = new Intl.DateTimeFormat('en-PH', {
          timeZone: 'Asia/Manila',
          hour: 'numeric',
          minute: '2-digit',
        }).format(new Date());
      }
    };
    updateClock();
    window.setInterval(updateClock, 30000);

    const forecastRows = ['yesterday', 'today', 'tomorrow'].map((day) => ({
      day,
      temperature: overviewContext.querySelector(`[data-weather-temperature="${day}"]`),
      condition: overviewContext.querySelector(`[data-weather-condition="${day}"]`),
      icon: overviewContext.querySelector(`[data-weather-icon="${day}"]`),
    }));
    if (forecastRows.every(({ temperature, condition, icon }) => temperature && condition && icon)) {
      const weatherUrl = 'https://api.open-meteo.com/v1/forecast?latitude=14.5995&longitude=120.9842&daily=weather_code,temperature_2m_max,temperature_2m_min&past_days=1&forecast_days=2&timezone=Asia%2FManila';
      const weatherRequest = new AbortController();
      const weatherTimeout = window.setTimeout(() => weatherRequest.abort(), 8000);
      const weatherDescriptions = new Map([
        [0, ['Clear', 'bi-sun']],
        [1, ['Mostly clear', 'bi-sun']],
        [2, ['Partly cloudy', 'bi-cloud-sun']],
        [3, ['Cloudy', 'bi-clouds']],
        [45, ['Foggy', 'bi-cloud-fog']],
        [48, ['Foggy', 'bi-cloud-fog']],
        [51, ['Light drizzle', 'bi-cloud-drizzle']],
        [56, ['Freezing drizzle', 'bi-cloud-drizzle']],
        [57, ['Freezing drizzle', 'bi-cloud-drizzle']],
        [53, ['Drizzle', 'bi-cloud-drizzle']],
        [55, ['Heavy drizzle', 'bi-cloud-drizzle']],
        [66, ['Freezing rain', 'bi-cloud-rain']],
        [67, ['Freezing rain', 'bi-cloud-rain']],
        [61, ['Light rain', 'bi-cloud-rain']],
        [63, ['Rain', 'bi-cloud-rain']],
        [65, ['Heavy rain', 'bi-cloud-rain']],
        [77, ['Snow grains', 'bi-cloud-snow']],
        [71, ['Light snow', 'bi-cloud-snow']],
        [73, ['Snow', 'bi-cloud-snow']],
        [75, ['Heavy snow', 'bi-cloud-snow']],
        [85, ['Snow showers', 'bi-cloud-snow']],
        [86, ['Heavy snow showers', 'bi-cloud-snow']],
        [80, ['Rain showers', 'bi-cloud-rain']],
        [81, ['Rain showers', 'bi-cloud-rain']],
        [82, ['Heavy showers', 'bi-cloud-rain-heavy']],
        [95, ['Thunderstorm', 'bi-cloud-lightning-rain']],
        [96, ['Thunderstorm', 'bi-cloud-lightning-rain']],
        [99, ['Thunderstorm', 'bi-cloud-lightning-rain']],
      ]);

      fetch(weatherUrl, { headers: { Accept: 'application/json' }, signal: weatherRequest.signal })
        .then((response) => {
          if (!response.ok) throw new Error(`Weather service returned HTTP ${response.status}`);
          return response.json();
        })
        .then((weather) => {
          const daily = weather.daily;
          if (!daily || !Array.isArray(daily.weather_code) || !Array.isArray(daily.temperature_2m_min)
            || !Array.isArray(daily.temperature_2m_max) || daily.weather_code.length < 3
            || daily.temperature_2m_min.length < 3 || daily.temperature_2m_max.length < 3) {
            throw new Error('Weather service returned an incomplete forecast');
          }
          forecastRows.forEach(({ day, temperature, condition, icon }, index) => {
            const min = Number(daily.temperature_2m_min[index]);
            const max = Number(daily.temperature_2m_max[index]);
            const code = Number(daily.weather_code[index]);
            if (!Number.isFinite(min) || !Number.isFinite(max) || !Number.isFinite(code)) {
              throw new Error(`Weather service returned incomplete ${day} forecast data`);
            }
            const [description, iconName] = weatherDescriptions.get(code) || ['Conditions', 'bi-cloud-sun'];
            condition.textContent = description;
            temperature.textContent = `${Math.round(min)}° – ${Math.round(max)}°C`;
            icon.className = `bi ${iconName}`;
            icon.setAttribute('aria-label', description);
          });
        })
        .catch((error) => {
          forecastRows.forEach(({ temperature, condition, icon }) => {
            temperature.textContent = 'Unavailable';
            condition.textContent = 'Forecast unavailable';
            icon.className = 'bi bi-cloud-slash';
          });
          console.error('[WLS] Could not load overview weather:', error);
        })
        .finally(() => {
          window.clearTimeout(weatherTimeout);
        });
    }
  }

  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (event) => {
      if (!window.confirm(el.getAttribute('data-confirm'))) event.preventDefault();
    });
  });

  const profileMenu = document.querySelector('.profile-menu');
  if (profileMenu) {
    document.addEventListener('click', (event) => {
      if (!profileMenu.contains(event.target)) profileMenu.open = false;
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && profileMenu.open) {
        profileMenu.open = false;
        profileMenu.querySelector('summary')?.focus();
      }
    });
  }

  document.querySelectorAll('[data-dismissible]').forEach((banner) => {
    const key = `wls-dismissed-${banner.dataset.dismissible}`;
    if (window.sessionStorage.getItem(key) === '1') banner.hidden = true;
    const close = banner.querySelector('.banner-dismiss');
    if (close) {
      close.addEventListener('click', () => {
        banner.hidden = true;
        window.sessionStorage.setItem(key, '1');
      });
    }
  });

  document.querySelectorAll('table').forEach((table) => {
    table.classList.add('table', 'table-striped', 'align-middle');
    let wrapper = table.closest('.tablewrap, .table-responsive');
    if (!wrapper) {
      wrapper = document.createElement('div');
      wrapper.className = 'table-responsive';
      table.parentNode.insertBefore(wrapper, table);
      wrapper.append(table);
    } else {
      wrapper.classList.add('table-responsive');
    }
  });

  document.querySelectorAll('.app-main input, .app-main select, .app-main textarea').forEach((control) => {
    if (control.matches('input[type="checkbox"], input[type="radio"], input[type="range"], input[type="color"], input[type="file"], input[type="hidden"], input[type="button"], input[type="submit"], input[type="reset"]')) {
      return;
    }
    const inTable = control.closest('.table-responsive');
    if (control.tagName === 'SELECT') {
      control.classList.add('form-select');
      if (inTable) control.classList.add('form-select-sm');
    } else {
      control.classList.add('form-control');
      if (inTable) control.classList.add('form-control-sm');
    }
  });

  document.querySelectorAll('[data-filter]').forEach((input) => {
    const target = document.querySelector(input.getAttribute('data-filter'));
    if (!target) return;
    if (target.closest('.tablewrap')) return;
    const rows = target.querySelectorAll('tbody tr');
    input.addEventListener('input', () => {
      const query = input.value.trim().toLowerCase();
      rows.forEach((row) => {
        row.hidden = query !== '' && !row.textContent.toLowerCase().includes(query);
      });
    });
  });

  document.querySelectorAll('.tablewrap table').forEach((table, index) => {
    const wrapper = table.closest('.tablewrap');
    const body = table.tBodies[0];
    if (!wrapper || !body || table.dataset.enhanced === 'true') return;

    wrapper.classList.add('table-responsive');
    table.classList.add('table', 'table-striped', 'align-middle');
    table.dataset.enhanced = 'true';
    const rows = Array.from(body.rows).filter((row) => !row.querySelector('[colspan]'));
    const toolbar = document.createElement('div');
    toolbar.className = 'table-tools';

    const searchWrap = document.createElement('div');
    searchWrap.className = 'table-search';
    const searchLabel = document.createElement('label');
    const search = document.createElement('input');
    const id = `table-search-${index}`;
    searchLabel.htmlFor = id;
    searchLabel.textContent = 'Search this table';
    search.type = 'search';
    search.id = id;
    search.className = 'form-control form-control-sm';
    search.placeholder = 'Type to filter rows';
    search.autocomplete = 'off';
    searchWrap.append(searchLabel, search);

    const sizeWrap = document.createElement('div');
    const sizeLabel = document.createElement('label');
    const size = document.createElement('select');
    const sizeId = `table-size-${index}`;
    sizeLabel.htmlFor = sizeId;
    sizeLabel.textContent = 'Rows per page';
    size.id = sizeId;
    size.className = 'form-select form-select-sm';
    [10, 25, 50].forEach((amount) => {
      const option = document.createElement('option');
      option.value = String(amount);
      option.textContent = String(amount);
      if (amount === 25) option.selected = true;
      size.append(option);
    });

    sizeWrap.append(sizeLabel, size);

    const csvButton = document.createElement('button');
    csvButton.type = 'button';
    csvButton.className = 'btn alt';
    csvButton.textContent = 'Export CSV';
    csvButton.addEventListener('click', () => {
      const csvRows = [Array.from(table.tHead?.rows[0]?.cells ?? []).map((cell) => cell.innerText)];
      filtered.forEach((row) => csvRows.push(Array.from(row.cells).map((cell) => cell.innerText.trim())));
      const csv = csvRows.map((row) => row.map((value) => {
        const safeValue = /^[=+\-@\t\r]/.test(value) ? `'${value}` : value;
        return `"${safeValue.replace(/"/g, '""')}"`;
      }).join(',')).join('\r\n');
      const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = `${table.id || `table-${index + 1}`}.csv`;
      link.click();
      URL.revokeObjectURL(link.href);
    });

    const printButton = document.createElement('button');
    printButton.type = 'button';
    printButton.className = 'btn alt';
    printButton.textContent = 'Print / Save PDF';
    printButton.addEventListener('click', () => window.print());
    toolbar.append(searchWrap, sizeWrap, csvButton, printButton);
    wrapper.before(toolbar);

    const emptyState = document.createElement('div');
    emptyState.className = 'table-empty-state';
    emptyState.setAttribute('role', 'status');
    emptyState.innerHTML = '<strong>No records found</strong><span>Try changing your search or filters.</span>';
    wrapper.append(emptyState);

    const pagination = document.createElement('div');
    pagination.className = 'table-pagination';
    const status = document.createElement('span');
    const controls = document.createElement('div');
    controls.className = 'table-pagination-controls';
    const previous = document.createElement('button');
    previous.type = 'button';
    previous.textContent = 'Previous';
    const pageLabel = document.createElement('span');
    pageLabel.setAttribute('aria-live', 'polite');
    const next = document.createElement('button');
    next.type = 'button';
    next.textContent = 'Next';
    controls.append(previous, pageLabel, next);
    pagination.append(status, controls);
    wrapper.after(pagination);

    let filtered = rows;
    let page = 0;
    const externalFilter = Array.from(document.querySelectorAll('[data-filter]'))
      .find((input) => input.getAttribute('data-filter') === `#${table.id}`);
    const render = () => {
      const pageSize = Number(size.value);
      const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
      page = Math.min(page, pages - 1);
      rows.forEach((row) => { row.hidden = true; });
      filtered.slice(page * pageSize, (page + 1) * pageSize).forEach((row) => { row.hidden = false; });
      wrapper.classList.toggle('is-empty', filtered.length === 0);
      status.textContent = filtered.length
        ? `Showing ${page * pageSize + 1}–${Math.min((page + 1) * pageSize, filtered.length)} of ${filtered.length} records`
        : '0 records';
      pageLabel.textContent = `Page ${page + 1} of ${pages}`;
      previous.disabled = page === 0;
      next.disabled = page >= pages - 1;
    };
    search.addEventListener('input', () => {
      const query = search.value.trim().toLocaleLowerCase();
      if (externalFilter) externalFilter.value = search.value;
      filtered = rows.filter((row) => row.textContent.toLocaleLowerCase().includes(query));
      page = 0;
      render();
    });
    if (externalFilter) {
      search.value = externalFilter.value;
      externalFilter.addEventListener('input', () => {
        search.value = externalFilter.value;
        const query = search.value.trim().toLocaleLowerCase();
        filtered = rows.filter((row) => row.textContent.toLocaleLowerCase().includes(query));
        page = 0;
        render();
      });
    }
    size.addEventListener('change', () => { page = 0; render(); });
    previous.addEventListener('click', () => { page -= 1; render(); });
    next.addEventListener('click', () => { page += 1; render(); });
    render();
  });

  document.querySelectorAll('[data-tabs]').forEach((tabs) => {
    const buttons = Array.from(tabs.querySelectorAll('[role="tab"]'));
    buttons.forEach((button) => {
      button.addEventListener('click', () => {
        buttons.forEach((tab) => {
          const selected = tab === button;
          tab.setAttribute('aria-selected', String(selected));
          const panel = document.getElementById(tab.getAttribute('aria-controls'));
          if (panel) panel.hidden = !selected;
        });
      });
    });
  });

  document.addEventListener('invalid', (event) => {
    const panel = event.target.closest('.tab-panel[hidden]');
    if (!panel) return;
    const tab = document.querySelector(`[aria-controls="${panel.id}"][role="tab"]`);
    if (tab) tab.click();
  }, true);

  document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', () => {
      form.querySelectorAll('button[type="submit"]').forEach((button) => {
        window.setTimeout(() => { button.disabled = true; }, 0);
      });
    });
  });
  window.addEventListener('pageshow', () => {
    document.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = false; });
  });
  document.querySelectorAll('.msg.success').forEach((message) => {
    window.setTimeout(() => { message.hidden = true; }, 6000);
  });
});
