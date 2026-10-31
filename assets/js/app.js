/**
 * Fornex Control — интерфейс панели.
 *
 * Один файл без сборки: маршруты на hash, запросы к /api/, модальные окна.
 * Никаких секретов в браузер не попадает — только CSRF-токен сессии.
 */

const boot = JSON.parse(document.getElementById('boot-data').textContent);

const state = {
  csrf: boot.csrf || '',
  logged: !!boot.logged_in,
  base: boot.base || '',
  meta: null,
  presets: null,
  domains: null,
  domainCache: new Map(),
};

/* ------------------------------------------------------------------ утилиты */

const esc = (value) =>
  String(value ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

const host = (value) => (value === '' || value === '@' ? '@' : value);
const short = (value, max = 64) => {
  const text = String(value ?? '');
  return text.length > max ? `${text.slice(0, max)}…` : text;
};

function toast(message, kind = 'info', timeout = 4200) {
  const root = document.getElementById('toasts');
  const node = document.createElement('div');
  node.className = `toast ${kind}`;
  node.textContent = message;
  root.appendChild(node);
  setTimeout(() => node.remove(), timeout);
}

async function api(path, options = {}) {
  const method = options.method || 'GET';
  const headers = { Accept: 'application/json' };
  let body;

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(options.body);
  }

  if (method !== 'GET' && state.csrf) {
    headers['X-CSRF-Token'] = state.csrf;
  }

  const response = await fetch(state.base + path, { method, headers, body, credentials: 'same-origin' });
  const text = await response.text();
  let payload = {};

  try {
    payload = text ? JSON.parse(text) : {};
  } catch (error) {
    throw {
      code: 'bad_response',
      message: `Ответ сервера не разобран как JSON (HTTP ${response.status}): ${text.slice(0, 200)}`,
      status: response.status,
    };
  }

  if (!response.ok || payload.ok === false) {
    const err = (payload && payload.error) || {};
    if (response.status === 401) {
      state.logged = false;
      render();
    }
    throw { code: err.code || 'http_error', message: err.message || `HTTP ${response.status}`, details: err.details || {}, status: response.status };
  }

  if (payload.csrf) {
    state.csrf = payload.csrf;
  }

  return payload;
}

/* ------------------------------------------------------------------- модалки */

function closeModal() {
  document.getElementById('overlay-root').innerHTML = '';
}

function openModal(title, bodyHtml, footerHtml = '', wide = false) {
  const root = document.getElementById('overlay-root');

  // ВАЖНО: .modal-backdrop — это центрирующая сетка (display:grid; place-items:center),
  // поэтому .modal обязан быть её РЕБЁНКОМ. Соседями они дают окно в левом нижнем
  // углу: .modal попадает в обычный поток, а подложка накрывает его и закрывает по клику.
  root.innerHTML = `
    <div class="modal-backdrop" id="modal-backdrop">
      <div class="modal ${wide ? 'wide' : ''}" role="dialog" aria-modal="true" aria-label="${esc(title)}">
        <div class="modal-head"><h2>${esc(title)}</h2><div class="spacer"></div>
          <button class="btn icon ghost" data-close="1" aria-label="Закрыть">✕</button></div>
        <div class="modal-body">${bodyHtml}</div>
        ${footerHtml ? `<div class="modal-foot">${footerHtml}</div>` : ''}
      </div>
    </div>`;

  const backdrop = root.querySelector('#modal-backdrop');
  const modal = root.querySelector('.modal');

  if (!backdrop || !modal) return;

  // Клик по самой подложке закрывает окно; клик внутри окна — нет.
  // mousedown, как в панели reg.ru: иначе выделение текста из окна наружу
  // завершается mouseup на подложке и окно закрывается вместе с правкой.
  backdrop.addEventListener('mousedown', (event) => {
    if (event.target === backdrop) closeModal();
  });

  modal.querySelectorAll('[data-close]').forEach((node) => {
    node.addEventListener('click', (event) => {
      event.preventDefault();
      closeModal();
    });
  });

  const first = modal.querySelector('input:not([type="hidden"]), textarea, select');

  if (first) {
    first.focus({ preventScroll: true });
  }
}

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closeModal();
});

/* ------------------------------------------------------------------ маршруты */

function parseRoute() {
  const raw = (location.hash || '#/').replace(/^#/, '');
  const parts = raw.split('/').filter(Boolean);

  if (parts.length === 0) return { view: 'domains' };
  if (parts[0] === 'audit') return { view: 'audit' };
  if (parts[0] === 'tokens') return { view: 'tokens' };
  if (parts[0] === 'd' && parts[1]) return { view: 'domain', domain: decodeURIComponent(parts[1]), tab: parts[2] || 'zone' };

  return { view: 'domains' };
}

function go(hash) {
  location.hash = hash;
}

/* ---------------------------------------------------------------- вход в панель */

function renderLogin() {
  document.getElementById('app').className = '';
  document.getElementById('app').innerHTML = `
    <div class="login-wrap">
      <form class="card login-card" id="login-form">
        <div class="login-head">
          <div class="brand-mark">FX</div>
          <div>
            <div class="page-title">Fornex Control</div>
            <div class="page-sub">домены, зона и почта домена</div>
          </div>
        </div>
        <div class="card-body login-form">
          <label class="field"><span>Логин</span><input type="text" name="login" autocomplete="username" value="admin" required></label>
          <label class="field"><span>Пароль</span><input type="password" name="password" autocomplete="current-password" required autofocus></label>
          <button class="btn block" type="submit">Войти</button>
          <p class="fnx-login-extra">Доступ только из домашней сети и с адреса владельца. Все действия записываются в журнал.</p>
        </div>
      </form>
    </div>`;

  document.getElementById('login-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = new FormData(event.target);

    try {
      await api('/api/login', { method: 'POST', body: { login: form.get('login'), password: form.get('password') } });
      state.logged = true;
      toast('Вход выполнен', 'ok');
      go('#/');
      render();
    } catch (error) {
      toast(error.message, 'bad');
    }
  });
}

/* ------------------------------------------------------------------ оболочка */

const NAV = [
  { id: 'domains', href: '#/', title: 'Домены' },
  { id: 'audit', href: '#/audit', title: 'Журнал' },
  { id: 'tokens', href: '#/tokens', title: 'Токены' },
];

function renderShell(route, contentHtml, actionsHtml = '', subHtml = '') {
  const nav = NAV.map((item) => {
    const active = route.view === item.id || (route.view === 'domain' && item.id === 'domains');
    return `<a class="nav-item ${active ? 'active' : ''}" href="${item.href}"><span>${esc(item.title)}</span></a>`;
  }).join('');

  document.getElementById('app').className = 'app';
  document.getElementById('app').innerHTML = `
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-mark">FX</div>
        <div class="brand-text">
          <div class="brand-name">Fornex Control</div>
          <div class="brand-sub">DNS · почта · NS</div>
        </div>
      </div>
      <nav class="nav">${nav}</nav>
      <div class="sidebar-foot">
        <div class="fnx-muted">версия ${esc(boot.version)}</div>
        <button class="btn ghost sm" id="logout">Выйти</button>
      </div>
    </aside>
    <main class="main">
      <div class="content" id="content">
        <div class="page-head">
          <div>
            <h1 class="page-title">${esc(pageTitle(route))}</h1>
            <div class="page-sub">${subHtml}</div>
          </div>
          <div class="page-actions fnx-actions">${actionsHtml}</div>
        </div>
        ${contentHtml}
      </div>
    </main>`;

  document.getElementById('logout').addEventListener('click', async () => {
    try {
      await api('/api/logout', { method: 'POST' });
    } catch (error) {
      /* выход не должен зависеть от ответа */
    }
    state.logged = false;
    renderLogin();
  });
}

function pageTitle(route) {
  if (route.view === 'domain') return route.domain;
  if (route.view === 'audit') return 'Журнал действий';
  if (route.view === 'tokens') return 'Токены для скриптов';
  return 'Домены аккаунта';
}

/* ------------------------------------------------------------- список доменов */

async function viewDomains() {
  let data;
  try {
    data = await api('/api/domains');
  } catch (error) {
    renderShell({ view: 'domains' }, `<div class="notice bad"><strong>Список не получен</strong>${esc(error.message)}</div>`);
    return;
  }

  state.domains = data.domains;

  const rows = data.domains.map((row) => {
    const chips = Object.entries(row.types).map(([type, count]) => `<span class="chip">${esc(type)} ${count}</span>`).join('');
    const mail = row.has_mx ? (row.has_spf ? 'почта есть' : 'MX без SPF') : 'без почты';
    const badge = row.has_mx && row.has_spf ? 'ok' : row.has_mx ? 'warn' : '';

    return `
      <tr>
        <td>
          <div class="fnx-domain-name">${esc(row.name)}</div>
          <div class="fnx-muted">A @: ${esc(row.apex || '—')} · обновлено ${esc((row.updated || '').slice(0, 16))}</div>
        </td>
        <td class="num">${row.entries}</td>
        <td><div class="fnx-chips">${chips || '<span class="fnx-muted">пусто</span>'}</div></td>
        <td><span class="badge ${badge}">${esc(mail)}</span></td>
        <td class="actions">
          <a class="btn sm" href="#/d/${encodeURIComponent(row.name)}/zone">Открыть</a>
          <a class="btn sm soft" href="#/d/${encodeURIComponent(row.name)}/wizard">Мастер</a>
        </td>
      </tr>`;
  }).join('');

  const empty = `<div class="empty"><strong>Зон нет</strong><div>Добавьте домен — или проверьте, что ключ имеет доступ к аккаунту.</div></div>`;

  const content = `
    ${data.error ? `<div class="notice bad"><strong>Fornex вернул ошибку</strong>${esc(data.error)}</div>` : ''}
    <div class="card">
      <div class="card-head"><h2>Зоны (${data.count})</h2></div>
      <div class="table-wrap">
        <table class="fnx-table">
          <thead><tr><th>Домен</th><th class="num">Записей</th><th>Записи</th><th>Почта</th><th></th></tr></thead>
          <tbody>${rows || `<tr><td colspan="5">${empty}</td></tr>`}</tbody>
        </table>
      </div>
    </div>
    <div class="notice info" style="margin-top:14px">
      <strong>Как это работает</strong>
      Зона читается из Fornex API. Изменения — всегда через предпросмотр: сначала план, потом запись.
      Перед каждой записью снимается копия зоны.
    </div>`;

  renderShell(
    { view: 'domains' },
    content,
    `<button class="btn" id="add-domain">Добавить домен</button>`,
    'живые зоны Fornex API · записи, почта Google, NS',
  );

  document.getElementById('add-domain').addEventListener('click', openAddDomain);
}

function openAddDomain() {
  openModal('Добавить домен', `
    <div class="form-grid">
      <label class="field"><span>Домен</span><input type="text" id="new-domain" placeholder="example.com"></label>
      <label class="field"><span>IPv4 для A-записи корня</span><input type="text" id="new-ip" value="${esc(boot.default_ip || '')}"></label>
    </div>
    <p class="fnx-muted">Fornex по умолчанию запрещает создание зон через API. Если запрет включён, панель покажет готовый текст тикета в поддержку — зону добавите в кабинете, остальное заработает сразу.</p>`,
  `<button class="btn ghost" data-close="1">Отмена</button><button class="btn" id="do-add">Добавить зону</button>`);

  document.getElementById('do-add').addEventListener('click', async () => {
    const name = document.getElementById('new-domain').value.trim();
    const ip = document.getElementById('new-ip').value.trim();

    try {
      const result = await api('/api/domains', { method: 'POST', body: { name, ip } });
      closeModal();
      toast(`Зона ${name} создана`, 'ok');
      if (result.created && Array.isArray(result.created.entry_set)) {
        toast(`Записей в новой зоне: ${result.created.entry_set.length}`, 'info');
      }
      go(`#/d/${encodeURIComponent(name)}/zone`);
    } catch (error) {
      if (error.code === 'zone_create_forbidden') {
        showForbidden(error.details, name);
        return;
      }
      toast(error.message, 'bad', 6000);
    }
  });
}

function showForbidden(details, domain) {
  const steps = (details.what_to_do || []).map((step) => `<li>${esc(step)}</li>`).join('');
  const ticket = details.ticket || '';

  openModal('Fornex не разрешает создавать зоны через API', `
    <div class="notice warn"><strong>Это ограничение аккаунта, а не ошибка панели</strong>
      Право «добавлять домены через API» у Fornex по умолчанию выключено. Записи, пресеты и проверки работают как обычно — нужна только сама зона ${esc(domain)}.</div>
    <ol style="margin:14px 0 0 18px; font-size:13.5px; line-height:1.6">${steps}</ol>
    <p style="margin-top:14px"><strong>Текст тикета в поддержку</strong></p>
    <div class="fnx-copybox" id="ticket-text">${esc(ticket)}</div>`,
  `<button class="btn ghost" data-close="1">Закрыть</button>
     <button class="btn soft" id="copy-ticket">Скопировать текст</button>
     <a class="btn" href="${esc(details.cabinet || 'https://ru.fornex.com/my/dns/')}" target="_blank" rel="noreferrer">Открыть кабинет Fornex</a>`, true);

  document.getElementById('copy-ticket').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(ticket);
      toast('Текст скопирован', 'ok');
    } catch (error) {
      toast('Браузер не дал доступ к буферу — выделите текст вручную', 'warn');
    }
  });
}

/* --------------------------------------------------------------- карточка домена */

async function viewDomain(route) {
  const domain = route.domain;
  const tab = route.tab;

  const tabs = [
    ['zone', 'Зона'],
    ['wizard', 'Мастер'],
    ['verify', 'Проверка'],
    ['ns', 'NS'],
  ].map(([id, title]) => `<a class="tab ${tab === id ? 'active' : ''}" href="#/d/${encodeURIComponent(domain)}/${id}">${title}</a>`).join('');

  const actions = `
    <button class="btn sm ghost" id="snapshot">Снимок зоны</button>
    <a class="btn sm ghost" href="${state.base}/api/domains/${encodeURIComponent(domain)}/zone-file" target="_blank" rel="noreferrer">Файл зоны</a>
    <button class="btn sm ghost danger-ghost" id="zone-delete">Удалить зону</button>`;

  renderShell({ view: 'domain', domain }, `
    <div class="card">
      <div class="tabs">${tabs}</div>
      <div class="fnx-tab-panel" id="tab-panel"><div class="skeleton" style="height:120px"></div></div>
    </div>`, actions, `зона ${esc(domain)} · источник — Fornex API`);

  document.getElementById('snapshot').addEventListener('click', async () => {
    try {
      const result = await api(`/api/domains/${encodeURIComponent(domain)}/snapshot`, { method: 'POST', body: { kind: 'manual' } });
      toast(`Снимок сохранён: ${result.snapshot.entries} записей`, 'ok');
    } catch (error) {
      toast(error.message, 'bad');
    }
  });

  document.getElementById('zone-delete').addEventListener('click', () => openDeleteZone(domain));

  const panel = document.getElementById('tab-panel');

  if (tab === 'zone') await renderZone(panel, domain);
  if (tab === 'wizard') await renderWizard(panel, domain);
  if (tab === 'verify') await renderVerify(panel, domain);
  if (tab === 'ns') await renderNs(panel, domain);
}

async function renderZone(panel, domain) {
  let data;
  try {
    data = await api(`/api/domains/${encodeURIComponent(domain)}/zone`);
  } catch (error) {
    panel.innerHTML = `<div class="notice bad"><strong>Зона не прочитана</strong>${esc(error.message)}</div>`;
    return;
  }

  const rows = data.entries.map((entry) => `
    <tr>
      <td class="mono">${entry.id}</td>
      <td><span class="chip">${esc(entry.type)}</span></td>
      <td class="fnx-host">${esc(host(entry.host))}</td>
      <td class="num">${entry.prio ?? ''}</td>
      <td class="num">${entry.ttl ?? ''}</td>
      <td class="value mono">${esc(short(entry.value, 90))}</td>
      <td class="actions">
        <button class="btn sm ghost" data-edit="${entry.id}">Изменить</button>
        <button class="btn sm danger-ghost" data-del="${entry.id}">Удалить</button>
      </td>
    </tr>`).join('');

  panel.innerHTML = `
    <div class="toolbar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
      <input type="text" id="rec-host" placeholder="имя (@, www, mail)" style="max-width:170px">
      <select id="rec-type" style="max-width:120px">
        ${['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'].map((type) => `<option>${type}</option>`).join('')}
      </select>
      <input type="text" id="rec-value" placeholder="значение" style="flex:1;min-width:220px">
      <input type="number" id="rec-prio" placeholder="prio" style="max-width:90px">
      <input type="number" id="rec-ttl" placeholder="ttl" style="max-width:90px">
      <button class="btn" id="rec-add">Добавить запись</button>
    </div>
    <div class="table-wrap">
      <table class="fnx-table">
        <thead><tr><th>id</th><th>тип</th><th>имя</th><th class="num">prio</th><th class="num">ttl</th><th>значение</th><th></th></tr></thead>
        <tbody>${rows || '<tr><td colspan="7"><div class="empty"><strong>Записей нет</strong></div></td></tr>'}</tbody>
      </table>
    </div>
    <p class="fnx-muted" style="margin-top:10px">Удаление и правка — только по id. Записи SOA и NS из панели не трогаются: они держат зону.</p>`;

  document.getElementById('rec-add').addEventListener('click', async () => {
    const body = {
      host: document.getElementById('rec-host').value.trim(),
      type: document.getElementById('rec-type').value,
      value: document.getElementById('rec-value').value.trim(),
      prio: document.getElementById('rec-prio').value || null,
      ttl: document.getElementById('rec-ttl').value || null,
    };

    try {
      await api(`/api/domains/${encodeURIComponent(domain)}/zone`, { method: 'POST', body });
      toast('Запись создана', 'ok');
      renderZone(panel, domain);
    } catch (error) {
      toast(error.message, 'bad');
    }
  });

  panel.querySelectorAll('[data-edit]').forEach((button) => {
    button.addEventListener('click', () => {
      const entry = data.entries.find((item) => String(item.id) === button.dataset.edit);
      openEditRecord(panel, domain, entry);
    });
  });

  panel.querySelectorAll('[data-del]').forEach((button) => {
    button.addEventListener('click', () => {
      const entry = data.entries.find((item) => String(item.id) === button.dataset.del);
      openDeleteRecord(panel, domain, entry);
    });
  });
}

function openEditRecord(panel, domain, entry) {
  openModal(`Изменить запись id ${entry.id}`, `
    <div class="form-grid">
      <label class="field"><span>Имя</span><input id="e-host" value="${esc(entry.host)}"></label>
      <label class="field"><span>Тип</span>
        <select id="e-type">${['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'].map((type) => `<option ${type === entry.type ? 'selected' : ''}>${type}</option>`).join('')}</select>
      </label>
      <label class="field"><span>Приоритет (MX/SRV)</span><input id="e-prio" type="number" value="${entry.prio ?? ''}"></label>
      <label class="field"><span>TTL</span><input id="e-ttl" type="number" value="${entry.ttl ?? ''}"></label>
    </div>
    <label class="field"><span>Значение</span><textarea id="e-value" rows="4">${esc(entry.value)}</textarea></label>`,
  `<button class="btn ghost" data-close="1">Отмена</button><button class="btn" id="e-save">Сохранить</button>`);

  document.getElementById('e-save').addEventListener('click', async () => {
    const body = {
      host: document.getElementById('e-host').value.trim(),
      type: document.getElementById('e-type').value,
      value: document.getElementById('e-value').value.trim(),
      prio: document.getElementById('e-prio').value || null,
      ttl: document.getElementById('e-ttl').value || null,
    };

    try {
      await api(`/api/domains/${encodeURIComponent(domain)}/zone/${entry.id}`, { method: 'PUT', body });
      closeModal();
      toast('Запись сохранена', 'ok');
      renderZone(panel, domain);
    } catch (error) {
      toast(error.message, 'bad');
    }
  });
}

function openDeleteRecord(panel, domain, entry) {
  openModal(`Удалить запись id ${entry.id}`, `
    <div class="notice warn"><strong>Проверьте запись перед удалением</strong>
      Перед удалением панель сохранит копию зоны. Отменить удаление можно только вручную из снимка.</div>
    <dl class="kv" style="margin-top:12px">
      <dt>тип</dt><dd>${esc(entry.type)}</dd>
      <dt>имя</dt><dd class="mono">${esc(host(entry.host))}</dd>
      <dt>значение</dt><dd class="mono">${esc(short(entry.value, 120))}</dd>
    </dl>`,
  `<button class="btn ghost" data-close="1">Отмена</button>
     <button class="btn danger" id="d-confirm">Удалить именно id ${entry.id}</button>`);

  document.getElementById('d-confirm').addEventListener('click', async () => {
    try {
      await api(`/api/domains/${encodeURIComponent(domain)}/zone/${entry.id}`, { method: 'DELETE' });
      closeModal();
      toast('Запись удалена', 'ok');
      renderZone(panel, domain);
    } catch (error) {
      toast(error.message, 'bad');
    }
  });
}

function openDeleteZone(domain) {
  openModal('Удалить зону целиком', `
    <div class="notice bad"><strong>Необратимо</strong>
      Удаление зоны уносит все её записи: сайт, почту, подтверждения. Панель снимает копию перед удалением,
      но восстановить записи в Fornex автоматически она не сможет — только вручную из снимка.</div>
    <label class="field" style="margin-top:14px"><span>Введите имя домена для подтверждения: <code>${esc(domain)}</code></span>
      <input id="z-confirm" placeholder="${esc(domain)}"></label>
    <label class="field"><span>Сухой прогон</span>
      <select id="z-dry"><option value="1">да, только снимок</option><option value="0">нет, удалить зону</option></select></label>`,
  `<button class="btn ghost" data-close="1">Отмена</button><button class="btn danger" id="z-do">Выполнить</button>`);

  document.getElementById('z-do').addEventListener('click', async () => {
    const confirm = document.getElementById('z-confirm').value.trim();
    const dry = document.getElementById('z-dry').value === '1';

    try {
      await api(`/api/domains/${encodeURIComponent(domain)}`, { method: 'DELETE', body: { confirm, dry_run: dry } });
      closeModal();
      toast(dry ? 'Снимок снят, зона не тронута' : 'Зона удалена', dry ? 'info' : 'warn');
      if (!dry) go('#/');
    } catch (error) {
      toast(error.message, 'bad');
    }
  });
}

/* ----------------------------------------------------------------- мастер */

const WIZARD_STATUS = {
  done: { badge: 'ok', label: 'готово' },
  todo: { badge: 'warn', label: 'нужно сделать' },
  blocked: { badge: '', label: 'ждёт предыдущий шаг' },
  empty: { badge: '', label: 'нет данных' },
};

// Введённое в шагах не теряется при перерисовке после применения шага.
const wizardValues = new Map();

function wizardValueKey(domain, presetId, param) {
  return `${domain}:${presetId}:${param}`;
}

async function renderWizard(panel, domain) {
  if (!state.presets) {
    try {
      state.presets = (await api('/api/presets')).presets;
    } catch (error) {
      panel.innerHTML = `<div class="notice bad"><strong>Пресеты не загружены</strong>${esc(error.message)}</div>`;
      return;
    }
  }

  const list = state.presets.map((preset) => `
    <button class="fnx-preset" data-preset="${esc(preset.id)}">
      <div class="t">${esc(preset.title)}${preset.stepwise ? ' <span class="badge">по шагам</span>' : ''}</div>
      <div class="d">${esc(preset.summary)}</div>
    </button>`).join('');

  panel.innerHTML = `
    <div class="fnx-grid-2">
      <div>
        <h3 style="margin:0 0 10px">Что настраиваем</h3>
        <div class="fnx-preset-list" id="preset-list">${list}</div>
      </div>
      <div id="wizard-body"><div class="empty"><strong>Выберите набор</strong><div>Шаги появятся справа.</div></div></div>
    </div>`;

  panel.querySelectorAll('[data-preset]').forEach((button) => {
    button.addEventListener('click', () => {
      panel.querySelectorAll('.fnx-preset').forEach((node) => node.classList.remove('active'));
      button.classList.add('active');
      openWizard(panel, domain, button.dataset.preset);
    });
  });

  const first = panel.querySelector('[data-preset]');
  if (first) {
    first.classList.add('active');
    openWizard(panel, domain, first.dataset.preset);
  }
}

async function openWizard(panel, domain, presetId) {
  const box = document.getElementById('wizard-body');
  if (!box) return;

  box.innerHTML = '<div class="skeleton" style="height:220px"></div>';

  let overview;
  try {
    const data = await api(`/api/domains/${encodeURIComponent(domain)}/wizard/${encodeURIComponent(presetId)}`);
    overview = data.wizard;
  } catch (error) {
    box.innerHTML = `<div class="notice bad"><strong>Шаги не загружены</strong>${esc(error.message)}</div>`;
    return;
  }

  const doneCount = overview.steps.filter((step) => step.status === 'done').length;

  box.innerHTML = `
    <h3 style="margin:0 0 4px">${esc(overview.title)}</h3>
    <p class="fnx-muted" style="margin:0 0 12px">${esc(overview.summary)}</p>
    <div class="notice ${overview.done ? 'ok' : 'info'}">
      <strong>${doneCount} из ${overview.steps.length} шагов готово</strong>
      ${overview.done ? 'Всё прописано — осталось дождаться, пока зона разойдётся по DNS.' : 'Шаги идут по порядку: следующий опирается на предыдущий.'}
    </div>
    <div style="margin-top:12px">${overview.steps.map((step, index) => wizardStepHtml(step, index + 1, domain, presetId)).join('')}</div>`;

  // Запоминаем ввод: после применения шага страница перерисовывается,
  // и вставленный ключ DKIM не должен пропадать.
  box.addEventListener('input', (event) => {
    const node = event.target;

    if (node && node.dataset && node.dataset.param) {
      wizardValues.set(wizardValueKey(domain, presetId, node.dataset.param), node.value);
    }
  });

  overview.steps.forEach((step) => bindWizardStep(box, domain, presetId, step, overview));
}

function wizardStepHtml(step, number, domain, presetId) {
  const status = WIZARD_STATUS[step.status] || WIZARD_STATUS.todo;
  const blocked = step.status === 'blocked';
  const blockedTitles = (step.blocked_by || []).map((item) => item.title).join(', ');

  const records = step.records.length
    ? `<div class="fnx-plan">${step.records.map((record) => `
        <div class="fnx-plan-row">
          <span class="chip">${esc(record.type)}</span>
          <div>
            <div class="fnx-host">${esc(host(record.host))}${record.prio != null ? ` · prio ${record.prio}` : ''}</div>
            <div class="why">${esc(record.why || '')}</div>
          </div>
        </div>`).join('')}</div>`
    : '<p class="fnx-muted" style="margin:0">Записей пока нет — заполните поле шага.</p>';

  const fields = step.fields.map((field) => {
    const saved = wizardValues.get(wizardValueKey(domain, presetId, field.key));
    const value = saved !== undefined ? saved : (field.default || '');

    if (field.type === 'select') {
      const options = (field.options || []).map((option) => `<option ${option === value ? 'selected' : ''}>${esc(option)}</option>`).join('');
      return `<label class="field"><span>${esc(field.label)}</span><select data-param="${esc(field.key)}">${options}</select>
        <small class="fnx-muted">${esc(field.hint)}</small></label>`;
    }

    if (field.type === 'textarea') {
      return `<label class="field"><span>${esc(field.label)}</span>
        <textarea rows="3" data-param="${esc(field.key)}" placeholder="v=DKIM1; k=rsa; p=…">${esc(value)}</textarea>
        <small class="fnx-muted">${esc(field.hint)}</small></label>`;
    }

    return `<label class="field"><span>${esc(field.label)}</span>
      <input type="text" data-param="${esc(field.key)}" value="${esc(value)}">
      <small class="fnx-muted">${esc(field.hint)}</small></label>`;
  }).join('');

  const counts = step.counts || { create: 0, update: 0, skip: 0, conflict: 0 };
  const summaryLine = step.status === 'done'
    ? (step.records.length ? 'Записи уже в зоне — менять нечего.' : 'Запись уже есть в зоне.')
    : (step.status === 'empty'
      ? `Нужно заполнить: ${esc(step.missing_param || 'поле шага')}`
      : `К записи: создать ${counts.create}, изменить ${counts.update}${counts.conflict ? `, конфликтов ${counts.conflict}` : ''}`);

  return `
    <section class="fnx-step ${esc(step.status)}" data-step="${esc(step.id)}">
      <header class="fnx-step-head">
        <div class="fnx-step-num">${number}</div>
        <div class="grow">
          <div class="fnx-step-title">${esc(step.title)} <span class="badge ${status.badge}">${status.label}</span></div>
          <div class="fnx-muted">${esc(step.summary)}</div>
        </div>
      </header>
      <div class="fnx-step-body">
        ${step.hint ? `<div class="notice ${blocked ? 'warn' : 'info'}"><strong>${blocked ? 'Сначала предыдущий шаг' : 'Что нужно'}</strong>${esc(step.hint)}</div>` : ''}
        ${blocked ? `<div class="notice warn" style="margin-top:8px"><strong>Шаг пока недоступен</strong>Сначала выполните: ${esc(blockedTitles)}.</div>` : ''}
        ${fields}
        ${records}
        <div class="fnx-actions" style="margin-top:12px">
          <button class="btn soft" data-action="preview" ${blocked ? 'disabled' : ''}>Показать план</button>
          <span class="fnx-muted">${esc(summaryLine)}</span>
        </div>
        <div class="fnx-step-plan"></div>
      </div>
    </section>`;
}

function bindWizardStep(box, domain, presetId, step, overview) {
  const section = box.querySelector(`[data-step="${step.id}"]`);
  if (!section) return;

  const previewButton = section.querySelector('[data-action="preview"]');
  if (!previewButton) return;

  previewButton.addEventListener('click', () => wizardPreview(section, domain, presetId, step));
}

function wizardParams(box) {
  const params = {};
  box.querySelectorAll('[data-param]').forEach((node) => {
    params[node.dataset.param] = node.value.trim();
  });
  return params;
}

async function wizardPreview(section, domain, presetId, step) {
  const slot = section.querySelector('.fnx-step-plan');
  slot.innerHTML = '<div class="skeleton" style="height:80px"></div>';

  const box = section.closest('#wizard-body');

  try {
    const data = await api(`/api/domains/${encodeURIComponent(domain)}/preset/${encodeURIComponent(presetId)}/preview`, {
      method: 'POST',
      body: { params: wizardParams(box), step: step.id },
    });

    const planRows = (kind, list) => list.map((item) => {
      let note;

      if (kind === 'update' && item.current) {
        note = `<div class="why mono">было: ${esc(short(item.current.value, 70))}<br>станет: ${esc(short(item.value, 70))}</div>`;
      } else if (kind === 'skip') {
        note = `<div class="why">уже есть</div>`;
      } else {
        note = `<div class="why">${esc(item.reason || short(item.value, 80) || item.why || '')}</div>`;
      }

      return `
      <div class="fnx-plan-row ${kind}">
        <span class="chip">${esc(item.type)}</span>
        <div>
          <div class="fnx-host">${esc(host(item.host))}${item.prio != null ? ` · prio ${item.prio}` : ''}</div>
          ${note}
        </div>
      </div>`;
    }).join('');

    const total = data.counts.create + data.counts.update;
    const notes = (data.notes || []).filter((note) => !note.startsWith('ни одной записи'));
    // Подтверждение — свойство шага: MX/SPF и DMARC применяются без ввода имени,
    // подтверждение владения и ключ подписи — только с явным подтверждением.
    const needConfirm = step.confirm !== false;

    slot.innerHTML = `
      ${notes.length ? `<div class="notice warn" style="margin-top:12px"><strong>Чего не хватает</strong>${notes.map((note) => esc(note)).join('<br>')}</div>` : ''}
      <div class="notice ${data.counts.conflict ? 'warn' : 'info'}" style="margin-top:12px">
        <strong>План шага</strong>
        создать ${data.counts.create} · изменить ${data.counts.update} · пропустить ${data.counts.skip} · конфликтов ${data.counts.conflict}
      </div>
      <div class="fnx-plan" style="margin-top:8px">
        ${planRows('create', data.plan.create)}
        ${planRows('update', data.plan.update)}
        ${planRows('skip', data.plan.skip)}
        ${planRows('conflict', data.plan.conflict)}
      </div>
      ${total > 0 ? (needConfirm ? `
        <label class="field" style="margin-top:12px"><span>Подтвердите запись: введите <code>${esc(domain)}</code></span>
          <input data-confirm placeholder="имя домена">
          <small class="fnx-muted" data-gate-hint>Кнопка включится, когда имя совпадёт посимвольно.</small></label>
        <div class="fnx-actions"><button class="btn" data-action="apply" aria-disabled="true">Применить шаг</button></div>` : `
        <div class="fnx-actions"><button class="btn" data-action="apply">Применить шаг</button></div>
        <p class="fnx-muted" style="margin-top:8px">Этот шаг только добавляет записи — подтверждение именем не требуется. Копия зоны всё равно снимается.</p>`) : ''}
      <div class="fnx-step-result"></div>`;

    const apply = slot.querySelector('[data-action="apply"]');

    if (apply) {
      // Синхронизация через делегирование: работает и если поле перерисовано,
      // и если ввод пришёл вставкой или автозаполнением.
      // Домены регистронезависимы, хвостовая точка — тоже часть имени.
      const normalize = (value) => String(value || '').trim().toLowerCase().replace(/\.$/, '');
      const hint = slot.querySelector('[data-gate-hint]');

      const sync = () => {
        if (!needConfirm) {
          apply.setAttribute('aria-disabled', 'false');

          return;
        }

        const input = slot.querySelector('[data-confirm]');
        const ready = !!input && normalize(input.value) === normalize(domain);

        // aria-disabled вместо disabled: кнопка остаётся кликабельной и сама
        // объясняет, чего не хватает, вместо мёртвого щелчка по серой кнопке.
        apply.setAttribute('aria-disabled', ready ? 'false' : 'true');

        if (hint) {
          hint.textContent = ready
            ? 'Имя совпало — можно применять.'
            : 'Кнопка включится, когда имя совпадёт посимвольно: ' + domain;
          hint.classList.toggle('text-ok', ready);
        }
      };

      const isReady = () => apply.getAttribute('aria-disabled') === 'false';

      slot.addEventListener('input', sync);
      slot.addEventListener('change', sync);
      slot.addEventListener('paste', () => setTimeout(sync, 0));
      slot.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && isReady() && event.target.matches('[data-confirm]')) {
          event.preventDefault();
          apply.click();
        }
      });
      sync();

      const confirmInput = slot.querySelector('[data-confirm]');
      if (confirmInput) confirmInput.focus({ preventScroll: true });

      apply.addEventListener('click', async () => {
        if (!isReady()) {
          const input = slot.querySelector('[data-confirm]');

          toast('Сначала введите имя домена в поле подтверждения — кнопка включится, когда оно совпадёт', 'warn', 6000);

          if (input) input.focus({ preventScroll: false });

          return;
        }

        apply.setAttribute('aria-disabled', 'true');

        try {
          const result = await api(`/api/domains/${encodeURIComponent(domain)}/preset/${encodeURIComponent(presetId)}/apply`, {
            method: 'POST',
            body: { params: wizardParams(box), step: step.id, confirm: domain },
          });

          const r = result.result;
          slot.querySelector('.fnx-step-result').innerHTML = `
            <div class="notice ok" style="margin-top:12px"><strong>Шаг выполнен</strong>
              создано ${r.created.length}, изменено ${r.updated.length}, пропущено ${r.skipped}, ошибок ${r.errors.length}.
              ${r.snapshot ? `Снимок: <code>${esc(r.snapshot.path.split('/').slice(-2).join('/'))}</code>` : ''}
            </div>
            ${r.errors.length ? `<div class="notice bad" style="margin-top:8px"><strong>Ошибки</strong>${r.errors.map((e) => esc(`${e.type || ''} ${e.detail || ''}`)).join('<br>')}</div>` : ''}`;

          toast('Шаг применён', 'ok');

          // Пересобираем шаги: следующий разблокируется, статусы обновятся.
          setTimeout(() => openWizard(document.body, domain, presetId), 900);
        } catch (error) {
          toast(error.message, 'bad', 6000);
          apply.setAttribute('aria-disabled', 'false');
        }
      });
    }
  } catch (error) {
    slot.innerHTML = `<div class="notice bad" style="margin-top:12px"><strong>План не построен</strong>${esc(error.message)}</div>`;
  }
}

/* --------------------------------------------------------------- проверка */

async function renderVerify(panel, domain) {
  panel.innerHTML = `
    <div class="fnx-actions" style="margin-bottom:12px">
      <button class="btn" id="v-run">Проверить сейчас</button>
      <span class="fnx-muted">источники: ns1.fornex.com и публичный резолвер</span>
    </div>
    <div id="v-report"><div class="empty"><strong>Проверка ещё не запускалась</strong><div>Прогон занимает несколько секунд.</div></div></div>`;

  const run = async () => {
    const box = document.getElementById('v-report');
    box.innerHTML = '<div class="skeleton" style="height:220px"></div>';

    try {
      const data = await api(`/api/domains/${encodeURIComponent(domain)}/verify`);
      const report = data.report;

      const checks = report.checks.map((check) => `
        <div class="fnx-check ${esc(check.status)}">
          <span class="dot-lg"></span>
          <div>
            <div class="title">${esc(check.title)}</div>
            <div class="detail">${esc(check.detail)}</div>
          </div>
        </div>`).join('');

      box.innerHTML = `
        <div class="notice ${report.summary.fail ? 'bad' : report.summary.warn ? 'warn' : 'ok'}">
          <strong>Итог</strong>
          ок ${report.summary.ok} · предупреждений ${report.summary.warn} · ошибок ${report.summary.fail} · пропущено ${report.summary.skip}
          <div class="fnx-muted" style="margin-top:4px">проверено ${esc(report.checked_at)} · ${esc(report.sources.tool)} · ${esc(report.sources.authoritative)}</div>
        </div>
        <div style="margin-top:12px">${checks}</div>`;
    } catch (error) {
      box.innerHTML = `<div class="notice bad"><strong>Проверка не выполнена</strong>${esc(error.message)}</div>`;
    }
  };

  document.getElementById('v-run').addEventListener('click', run);
}

/* --------------------------------------------------------------------- NS */

async function renderNs(panel, domain) {
  panel.innerHTML = '<div class="skeleton" style="height:180px"></div>';

  try {
    const data = await api(`/api/domains/${encodeURIComponent(domain)}/ns`);
    const ns = data.ns;

    const parent = ns.parent.length ? ns.parent.map((item) => `<span class="chip">${esc(item)}</span>`).join('') : '<span class="fnx-muted">не найдены</span>';
    const zone = ns.zone_ns.length ? ns.zone_ns.map((item) => `<span class="chip">${esc(item)}</span>`).join('') : '<span class="fnx-muted">нет</span>';

    panel.innerHTML = `
      <div class="notice ${ns.delegated ? 'ok' : 'warn'}">
        <strong>${ns.delegated ? 'Домен делегирован на Fornex' : 'Делегирование неполное'}</strong>
        ${ns.delegated
          ? 'NS у регистратора совпадают с ожидаемыми — зона обслуживается Fornex.'
          : `Не хватает: ${esc((ns.missing || []).join(', ') || '—')}`}
      </div>
      <div class="fnx-grid-2" style="margin-top:14px">
        <div class="card"><div class="card-head"><h3>NS у регистратора</h3></div>
          <div class="card-body"><div class="fnx-chips">${parent}</div>
            <p class="fnx-muted" style="margin-top:8px">источник: ${esc(ns.resolver)}</p></div></div>
        <div class="card"><div class="card-head"><h3>NS в зоне Fornex</h3></div>
          <div class="card-body"><div class="fnx-chips">${zone}</div>
            <p class="fnx-muted" style="margin-top:8px">записи NS держат зону — из панели они не удаляются</p></div></div>
      </div>
      <div style="margin-top:14px">
        <p class="fnx-muted"><strong>${ns.can_change_via_api ? 'Домен заказан у Fornex: NS меняются кнопкой' : 'Инструкция для регистратора'}</strong></p>
        <div class="fnx-copybox" id="ns-text">${esc(ns.instruction)}</div>
        <div class="fnx-actions" style="margin-top:10px">
          <button class="btn soft" id="ns-copy">Скопировать</button>
          ${ns.can_change_via_api ? '<button class="btn" id="ns-change">Сменить NS через API</button>' : ''}
        </div>
      </div>`;

    document.getElementById('ns-copy').addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(ns.instruction);
        toast('Инструкция скопирована', 'ok');
      } catch (error) {
        toast('Браузер не дал доступ к буферу', 'warn');
      }
    });

    const change = document.getElementById('ns-change');
    if (change) {
      change.addEventListener('click', () => openNsChange(panel, domain, ns));
    }
  } catch (error) {
    panel.innerHTML = `<div class="notice bad"><strong>Состояние NS не получено</strong>${esc(error.message)}</div>`;
  }
}

function openNsChange(panel, domain, ns) {
  openModal('Смена NS через API', `
    <div class="notice warn"><strong>Меняются NS всего домена</strong>
      После смены домен какое-то время может отвечать нестабильно: TLD обновляется не сразу.</div>
    <p class="fnx-muted" style="margin-top:10px">Будет установлено: ${esc(ns.desired.join(', '))}</p>`,
  `<button class="btn ghost" data-close="1">Отмена</button>
     <button class="btn soft" id="ns-dry">Сухой прогон</button>
     <button class="btn" id="ns-apply">Сменить</button>`);

  const send = async (dry) => {
    try {
      await api(`/api/domains/${encodeURIComponent(domain)}/ns`, { method: 'POST', body: { dry_run: dry } });
      toast(dry ? 'Сухой прогон выполнен (см. журнал)' : 'NS изменены', dry ? 'info' : 'ok');
      closeModal();
      renderNs(panel, domain);
    } catch (error) {
      toast(error.message, 'bad', 6000);
    }
  };

  document.getElementById('ns-dry').addEventListener('click', () => send(true));
  document.getElementById('ns-apply').addEventListener('click', () => send(false));
}

/* ------------------------------------------------------------------ журнал */

async function viewAudit() {
  let data;
  try {
    data = await api('/api/audit?limit=150');
  } catch (error) {
    renderShell({ view: 'audit' }, `<div class="notice bad"><strong>Журнал не прочитан</strong>${esc(error.message)}</div>`);
    return;
  }

  const rows = data.events.map((event) => `
    <tr>
      <td class="mono">${esc((event.created_at || '').replace('T', ' ').slice(0, 19))}</td>
      <td>${esc(event.actor)}</td>
      <td><span class="chip">${esc(event.action)}</span></td>
      <td class="fnx-host">${esc(event.target || '—')}</td>
      <td>${event.dry_run ? '<span class="badge warn">план</span>' : `<span class="badge ${event.status === 'ok' ? 'ok' : event.status === 'denied' ? 'bad' : 'warn'}">${esc(event.status)}</span>`}</td>
      <td>${esc(short(event.summary, 90))}</td>
    </tr>`).join('');

  const snaps = data.snapshots.map((snap) => `
    <li class="list-item">
      <div>
        <div class="fnx-host">${esc(snap.domain)} · ${esc(snap.kind)}</div>
        <div class="fnx-muted">${esc((snap.created_at || '').replace('T', ' ').slice(0, 19))} · ${snap.entries} записей · ${esc(snap.sha256.slice(0, 12))}</div>
      </div>
    </li>`).join('');

  renderShell({ view: 'audit' }, `
    <div class="card">
      <div class="card-head"><h2>Действия (${data.events.length})</h2></div>
      <div class="table-wrap">
        <table class="fnx-table">
          <thead><tr><th>время</th><th>кто</th><th>действие</th><th>объект</th><th>итог</th><th>что сделано</th></tr></thead>
          <tbody>${rows || '<tr><td colspan="6"><div class="empty"><strong>Пока пусто</strong></div></td></tr>'}</tbody>
        </table>
      </div>
    </div>
    <div class="card" style="margin-top:14px">
      <div class="card-head"><h2>Снимки зон (${data.snapshots.length})</h2></div>
      <div class="card-body"><ul class="list">${snaps || '<li class="fnx-muted">снимков нет</li>'}</ul>
        <p class="fnx-muted" style="margin-top:8px">Файлы лежат в каталоге SNAPSHOT_DIR, вне корня сайта: &lt;домен&gt;/&lt;дата&gt;.bind и .json</p></div>
    </div>`, '', 'каждое изменение, сухой прогон и отказ — с причиной');
}

/* ------------------------------------------------------------------ токены */

async function viewTokens() {
  let data;
  try {
    data = await api('/api/tokens');
  } catch (error) {
    renderShell({ view: 'tokens' }, `<div class="notice bad"><strong>Токены не прочитаны</strong>${esc(error.message)}</div>`);
    return;
  }

  const rows = data.tokens.map((token) => `
    <tr>
      <td class="mono">${token.id}</td>
      <td>${esc(token.name)}</td>
      <td class="mono">${esc(token.prefix)}…</td>
      <td>${token.write_access ? '<span class="badge warn">запись</span>' : '<span class="badge">чтение</span>'}</td>
      <td class="fnx-muted">${esc((token.last_used_at || '').replace('T', ' ').slice(0, 19) || '—')}</td>
      <td>${token.revoked_at ? '<span class="badge bad">отозван</span>' : `<button class="btn sm danger-ghost" data-revoke="${token.id}">Отозвать</button>`}</td>
    </tr>`).join('');

  renderShell({ view: 'tokens' }, `
    <div class="card">
      <div class="card-head"><h2>Токены (${data.tokens.length})</h2></div>
      <div class="table-wrap">
        <table class="fnx-table">
          <thead><tr><th>id</th><th>название</th><th>префикс</th><th>права</th><th>последнее использование</th><th></th></tr></thead>
          <tbody>${rows || '<tr><td colspan="6"><div class="empty"><strong>Токенов нет</strong></div></td></tr>'}</tbody>
        </table>
      </div>
      <div class="card-body">
        <div class="fnx-actions">
          <input id="t-name" placeholder="название (например, cron-verify)" style="max-width:280px">
          <label class="fnx-muted"><input type="checkbox" id="t-write"> разрешить запись</label>
          <button class="btn" id="t-create">Выпустить токен</button>
        </div>
        <p class="fnx-muted" style="margin-top:10px">Токен нужен скриптам: <code>Authorization: Bearer &lt;токен&gt;</code>. Значение показывается один раз.</p>
      </div>
    </div>`, '', 'доступ для cron и скриптов без пароля панели');

  document.getElementById('t-create').addEventListener('click', async () => {
    try {
      const result = await api('/api/tokens', {
        method: 'POST',
        body: { name: document.getElementById('t-name').value.trim(), write: document.getElementById('t-write').checked },
      });

      openModal('Токен создан', `
        <div class="notice warn"><strong>Скопируйте сейчас</strong> Значение больше не будет показано.</div>
        <div class="fnx-copybox" style="margin-top:12px" id="token-value">${esc(result.token.token)}</div>`,
      `<button class="btn" id="t-copy">Скопировать</button><button class="btn ghost" data-close="1">Закрыть</button>`);

      document.getElementById('t-copy').addEventListener('click', async () => {
        await navigator.clipboard.writeText(result.token.token);
        toast('Токен скопирован', 'ok');
      });

      viewTokens();
    } catch (error) {
      toast(error.message, 'bad');
    }
  });

  document.querySelectorAll('[data-revoke]').forEach((button) => {
    button.addEventListener('click', async () => {
      try {
        await api(`/api/tokens/${button.dataset.revoke}`, { method: 'DELETE' });
        toast('Токен отозван', 'ok');
        viewTokens();
      } catch (error) {
        toast(error.message, 'bad');
      }
    });
  });
}

/* ------------------------------------------------------------------ рендер */

function render() {
  if (!state.logged) {
    renderLogin();
    return;
  }

  const route = parseRoute();

  if (route.view === 'domains') viewDomains();
  else if (route.view === 'domain') viewDomain(route);
  else if (route.view === 'audit') viewAudit();
  else if (route.view === 'tokens') viewTokens();
}

window.addEventListener('hashchange', render);
render();
