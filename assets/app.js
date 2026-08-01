(() => {
  const $ = (sel) => document.querySelector(sel);
  const view = $('#view');
  const statusEl = $('#status');

  const state = {
    mode: 'per_semester',
    filters: null,
    ujianDetail: null,
    sekolahEditId: '',
    user: null,
    caps: {},
    roles: {},
    userEditId: '',
    csrf: '',
    viewData: null,
    tableSort: {
      per_semester: { key: 'nama', dir: 'asc' },
      semua_semester: { key: 'nama', dir: 'asc' },
      nilai_ijazah: { key: 'nama', dir: 'asc' },
    },
    apiCache: Object.create(null),
    abortCtrl: null,
    loadToken: 0,
    renderToken: 0,
  };

  function can(cap) {
    return !!state.caps[cap];
  }

  /** Map kode tingkat → nilai KKTP dari payload / cache filter. */
  function kktpNilaiMap(data) {
    const src = (data && data.kktp) || (state.filters && state.filters.kktp) || {};
    const map = {};
    (src.tingkat || []).forEach((t) => {
      if (!t || t.kode == null) return;
      const n = Number(t.nilai);
      if (!Number.isNaN(n)) map[String(t.kode).toUpperCase()] = n;
    });
    return map;
  }

  function normalizeTingkatToken(token) {
    const t = String(token || '').trim().toUpperCase();
    const roman = {
      I: 1, II: 2, III: 3, IV: 4, V: 5, VI: 6,
      VII: 7, VIII: 8, IX: 9, X: 10, XI: 11, XII: 12,
    };
    if (roman[t]) return t;
    if (!/^\d{1,2}$/.test(t)) return null;
    const n = Number(t);
    const map = {
      1: 'I', 2: 'II', 3: 'III', 4: 'IV', 5: 'V', 6: 'VI',
      7: 'VII', 8: 'VIII', 9: 'IX', 10: 'X', 11: 'XI', 12: 'XII',
    };
    return map[n] || null;
  }

  function parseTingkatKelas(kelas) {
    const nama = String(kelas || '').trim();
    if (!nama) return null;
    let m = nama.match(/^(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)\b/i);
    if (m) return m[1].toUpperCase();
    m = nama.match(/\bkelas\s*(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I|\d{1,2})\b/i);
    if (m) return normalizeTingkatToken(m[1]);
    m = nama.match(/^(\d{1,2})\b/);
    if (m) return normalizeTingkatToken(m[1]);
    return null;
  }

  /** Interval predikat dari KKTP (rumus RDM). KKTP 75 → 0–74 / 75–82 / 83–91 / 92–100. */
  function kktpIntervals(kktp) {
    const k = Math.max(0, Math.min(100, Number(kktp)));
    if (!Number.isFinite(k)) return null;
    if (k >= 100) {
      return {
        kktp: 100,
        belum: { min: 0, max: 99, label: 'Belum Tercapai' },
        cukup: { min: 100, max: 100, label: 'Cukup' },
        baik: { min: 100, max: 100, label: 'Baik' },
        sangat_baik: { min: 100, max: 100, label: 'Sangat Baik' },
      };
    }
    const span = 100 - k;
    let cukupMax = k + Math.round(span / 3) - 1;
    let baikMax = k + Math.round((2 * span) / 3) - 1;
    cukupMax = Math.max(k, Math.min(99, cukupMax));
    baikMax = Math.max(cukupMax + 1, Math.min(99, baikMax));
    const belumMax = k > 0 ? Math.ceil(k) - 1 : 0;
    return {
      kktp: k,
      belum: { min: 0, max: Math.max(0, belumMax), label: 'Belum Tercapai' },
      cukup: { min: k, max: cukupMax, label: 'Cukup' },
      baik: { min: cukupMax + 1, max: baikMax, label: 'Baik' },
      sangat_baik: { min: Math.min(100, baikMax + 1), max: 100, label: 'Sangat Baik' },
    };
  }

  function kktpPredikatBand(nilai, kktp) {
    const n = Number(nilai);
    const k = Number(kktp);
    if (!Number.isFinite(n) || !Number.isFinite(k)) return null;
    if (n < k) return 'belum';
    const iv = kktpIntervals(k);
    if (!iv) return null;
    if (n <= iv.cukup.max) return 'cukup';
    if (n <= iv.baik.max) return 'baik';
    return 'sangat_baik';
  }

  function fmtIntervalBound(n) {
    if (!Number.isFinite(Number(n))) return '—';
    const x = Number(n);
    return Number.isInteger(x) ? String(x) : String(Math.round(x * 100) / 100);
  }

  /** Sel nilai: font hitam (merah &lt; KKTP); opts.intervalCells = latar putih/biru/hijau. */
  function excelScoreCell(v, kelasOrTingkat, kktpMap, digits = 0, opts = {}) {
    if (v == null || v === '' || Number.isNaN(Number(v))) {
      return `<td class="num">—</td>`;
    }
    let n = Number(v);
    if (opts.roundInt === true || digits === 0) {
      n = Math.round(n);
    }
    const key = String(kelasOrTingkat || '').toUpperCase();
    const tingkat = (kktpMap && Object.prototype.hasOwnProperty.call(kktpMap, key))
      ? key
      : parseTingkatKelas(kelasOrTingkat);
    const thr = tingkat != null && kktpMap ? kktpMap[tingkat] : null;
    const band = thr != null ? kktpPredikatBand(n, thr) : null;
    const plain = opts.plainKktp === true;
    const intervalCells = opts.intervalCells === true;
    let cls = 'nilai-excel';
    if (band === 'belum') {
      cls += ' nilai-bawah-kktp';
      if (intervalCells) cls += ' nilai-cell-belum';
    } else if (intervalCells) {
      if (band === 'sangat_baik') cls += ' nilai-cell-sb';
      else if (band === 'baik') cls += ' nilai-cell-baik';
      else if (band === 'cukup') cls += ' nilai-cell-cukup';
    } else if (!plain) {
      if (band === 'sangat_baik') cls += ' nilai-sangat-baik';
      else if (band === 'baik') cls += ' nilai-baik';
    }
    const title = band === 'belum'
      ? `Belum Tercapai · di bawah KKTP tingkat ${tingkat} (${thr})`
      : (thr != null
        ? `${band === 'sangat_baik' ? 'Sangat Baik' : band === 'baik' ? 'Baik' : 'Cukup'} · KKTP ${tingkat}: ${thr}`
        : 'Nilai rapor');
    const text = (opts.roundInt === true || digits === 0)
      ? fmt(n, 0)
      : (opts.asRata ? fmtRata(n) : fmt(n, digits));
    const inner = opts.strong ? `<strong>${text}</strong>` : text;
    return `<td class="num ${cls}" title="${esc(title)}">${inner}</td>`;
  }

  function currentSort(mode = state.mode) {
    if (!state.tableSort[mode]) {
      state.tableSort[mode] = { key: 'nama', dir: 'asc' };
    }
    return state.tableSort[mode];
  }

  function sortBtn(mode, key, label) {
    const s = currentSort(mode);
    const active = s.key === key;
    const arrow = !active ? '↕' : (s.dir === 'asc' ? '↑' : '↓');
    const title = active
      ? `Urut ${label} (${s.dir === 'asc' ? 'naik' : 'turun'}) — klik untuk balik`
      : `Urutkan ${label}`;
    return `<button type="button" class="sort-btn${active ? ' active' : ''}" data-sort-mode="${esc(mode)}" data-sort-key="${esc(key)}" title="${esc(title)}" aria-label="${esc(title)}">${arrow}</button>`;
  }

  function sortHeader(mode, key, label, thClass = '', title = '') {
    const titleAttr = title ? ` title="${esc(title)}"` : '';
    return `<th class="${thClass}"${titleAttr}><span class="th-sort"><span>${esc(label)}</span>${sortBtn(mode, key, label)}</span></th>`;
  }

  function mapelScoreOf(row, key) {
    if (key.startsWith('score:')) {
      const sub = key.slice('score:'.length);
      const v = row?.scores?.[sub];
      return v == null || Number.isNaN(Number(v)) ? null : Number(v);
    }
    if (key.startsWith('mapel:')) {
      const kode = key.slice('mapel:'.length);
      const v = row?.nilai_mapel?.[kode];
      return v == null || Number.isNaN(Number(v)) ? null : Number(v);
    }
    if (key.startsWith('sem:')) {
      const ke = Number(key.slice('sem:'.length));
      const rowSem = (row?.semesters || []).find((x) => Number(x.semester_ke) === ke);
      const v = rowSem?.rata_rata;
      return v == null || Number.isNaN(Number(v)) ? null : Number(v);
    }
    return null;
  }

  function sortSiswaRows(list, key, dir) {
    const mul = dir === 'asc' ? 1 : -1;
    const rows = [...(list || [])];
    const numKeys = new Set([
      'jumlah', 'rata_rata', 'rank',
      'total_jumlah', 'rata_rata_semua', 'rank_keseluruhan', 'semester_count',
      'rata_ijazah', 'rata_rataan', 'rata_praktek', 'rata_teori', 'mapel_count',
    ]);
    const isMapelKey = key.startsWith('score:') || key.startsWith('mapel:') || key.startsWith('sem:');
    rows.sort((a, b) => {
      if (key === 'nama') {
        const c = String(a.nama || '').localeCompare(String(b.nama || ''), 'id', { sensitivity: 'base' });
        return mul * c;
      }
      if (key === 'nisn' || key === 'nis' || key === 'kelas' || key === 'kelas_akhir') {
        const c = String(a[key] || '').localeCompare(String(b[key] || ''), 'id', { sensitivity: 'base' });
        if (c !== 0) return mul * c;
        return String(a.nama || '').localeCompare(String(b.nama || ''), 'id', { sensitivity: 'base' });
      }
      if (numKeys.has(key) || isMapelKey) {
        const va = isMapelKey
          ? mapelScoreOf(a, key)
          : (a[key] == null || Number.isNaN(Number(a[key])) ? null : Number(a[key]));
        const vb = isMapelKey
          ? mapelScoreOf(b, key)
          : (b[key] == null || Number.isNaN(Number(b[key])) ? null : Number(b[key]));
        if (va == null && vb == null) {
          return String(a.nama || '').localeCompare(String(b.nama || ''), 'id', { sensitivity: 'base' });
        }
        if (va == null) return 1;
        if (vb == null) return -1;
        if (va === vb) {
          return String(a.nama || '').localeCompare(String(b.nama || ''), 'id', { sensitivity: 'base' });
        }
        return mul * (va < vb ? -1 : 1);
      }
      return 0;
    });
    return rows;
  }

  function applyTableSort(mode, key) {
    const s = currentSort(mode);
    if (s.key === key) {
      s.dir = s.dir === 'asc' ? 'desc' : 'asc';
    } else {
      s.key = key;
      // Nama/teks: A→Z; nilai: tinggi→rendah
      s.dir = (key === 'nama' || key === 'nisn' || key === 'nis' || key === 'kelas' || key === 'kelas_akhir')
        ? 'asc'
        : 'desc';
    }
    if (!state.viewData) return;
    if (mode === 'per_semester') renderPerSemester(state.viewData);
    else if (mode === 'semua_semester') renderSemuaSemester(state.viewData);
    else if (mode === 'nilai_ijazah') renderIjazahDaftar(state.viewData);
  }

  function setCsrf(token) {
    if (token) state.csrf = String(token);
  }

  function redirectLogin() {
    const next = encodeURIComponent(window.location.pathname.split('/').pop() || 'index.php');
    window.location.href = `login.php?next=${next}`;
  }

  function handleAuthError(json) {
    if (json && (json.code === 'UNAUTHORIZED' || json.error === 'UNAUTHORIZED' || json.code === 'CSRF')) {
      if (json.code === 'CSRF') {
        showStatus('Sesi keamanan kedaluwarsa. Memuat ulang…', true);
        setTimeout(() => window.location.reload(), 800);
        return true;
      }
      redirectLogin();
      return true;
    }
    return false;
  }

  function applyAuthUI() {
    const chip = $('#userChip');
    if (chip && state.user) {
      chip.hidden = false;
      const nameEl = $('#userChipName');
      const roleEl = $('#userChipRole');
      if (nameEl) nameEl.textContent = state.user.nama || state.user.username || '—';
      if (roleEl) {
        const sid = state.user.sekolah_id ? ` · ${state.user.sekolah_id}` : '';
        roleEl.textContent = (state.user.role_label || state.user.role || '—') + sid;
      }
    }

    document.querySelectorAll('.tab[data-cap]').forEach((tab) => {
      const ok = can(tab.dataset.cap);
      tab.hidden = !ok;
      if (!ok && tab.classList.contains('active')) {
        tab.classList.remove('active');
      }
    });

    const sw = $('#sekolahSwitchWrap');
    if (sw) sw.hidden = sw.hidden || !can('sekolah');
    // Ganti sekolah aktif hanya untuk superadmin (sekolah_manage)
    if (sw && !can('sekolah_manage')) sw.hidden = true;

    const btnRefresh = $('#btnRefresh');
    if (btnRefresh) btnRefresh.hidden = !can('impor');

    const activeTab = document.querySelector('.tab.active:not([hidden])');
    if (!activeTab) {
      const first = document.querySelector('.tab:not([hidden])');
      if (first) {
        state.mode = first.dataset.mode;
        first.classList.add('active');
        first.setAttribute('aria-selected', 'true');
      }
    }
  }

  function qs(params) {
    const p = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && String(v) !== '') p.set(k, v);
    });
    return p.toString();
  }

  function viewCacheKey(action, params = {}) {
    return `${action}?${qs(params)}`;
  }

  function clearApiCache() {
    state.apiCache = Object.create(null);
  }

  async function api(action, params = {}, opts = {}) {
    const cacheable = opts.cache !== false
      && !['refresh', 'health'].includes(action);
    const key = viewCacheKey(action, params);
    if (cacheable && state.apiCache[key]) {
      return state.apiCache[key];
    }

    if (state.abortCtrl && opts.abort !== false) {
      try { state.abortCtrl.abort(); } catch (_) { /* ignore */ }
    }
    const ctrl = opts.signal ? null : new AbortController();
    if (ctrl) state.abortCtrl = ctrl;

    const url = `api.php?${qs({ action, ...params })}`;
    const res = await fetch(url, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
      signal: opts.signal || ctrl?.signal,
    });
    const json = await res.json();
    if (handleAuthError(json)) throw new Error(json.error || 'Unauthorized');
    if (!json.ok) throw new Error(json.error || 'Gagal memuat data');
    if (json.data?.csrf) setCsrf(json.data.csrf);
    if (cacheable) state.apiCache[key] = json.data;
    return json.data;
  }

  async function apiPost(action, body = {}) {
    clearApiCache();
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': state.csrf || '',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ action, csrf: state.csrf || '', ...body }),
    });
    const json = await res.json();
    if (handleAuthError(json)) throw new Error(json.error || 'Unauthorized');
    if (!json.ok) throw new Error(json.error || 'Gagal menyimpan data');
    if (json.data?.csrf) setCsrf(json.data.csrf);
    return json.data;
  }

  async function apiUpload(formData) {
    const action = formData.get('action') || 'upload_excel';
    if (!formData.has('csrf')) formData.append('csrf', state.csrf || '');
    const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-CSRF-Token': state.csrf || '',
      },
      credentials: 'same-origin',
      body: formData,
    });
    const json = await res.json();
    if (handleAuthError(json)) throw new Error(json.error || 'Unauthorized');
    if (!json.ok) throw new Error(json.error || 'Gagal mengunggah file');
    if (json.data?.csrf) setCsrf(json.data.csrf);
    return json.data;
  }

  function formatBytes(n) {
    if (!n) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let v = n;
    while (v >= 1024 && i < u.length - 1) {
      v /= 1024;
      i++;
    }
    return `${v.toFixed(i ? 1 : 0)} ${u[i]}`;
  }

  function currentFilters() {
    return {
      tahun_ajaran: $('#fTahun').value,
      semester: $('#fSemester').value,
      kelas: $('#fKelas').value,
      id: $('#fSiswa').value,
    };
  }

  function showStatus(msg, isError = false) {
    if (!msg) {
      statusEl.hidden = true;
      statusEl.textContent = '';
      return;
    }
    statusEl.hidden = false;
    statusEl.textContent = msg;
    statusEl.classList.toggle('error', isError);
  }

  function fmt(n, digits = 2) {
    if (n === null || n === undefined || Number.isNaN(n)) return '—';
    return Number(n).toLocaleString('id-ID', {
      minimumFractionDigits: Number.isInteger(n) ? 0 : digits,
      maximumFractionDigits: digits,
    });
  }

  /** Format rataan/rata-rata: selalu 1 angka di belakang koma. */
  function fmtRata(n) {
    if (n === null || n === undefined || Number.isNaN(n)) return '—';
    return Number(n).toLocaleString('id-ID', {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    });
  }

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fillSelect(el, items, placeholder, mapFn) {
    const cur = el.value;
    el.innerHTML = '';
    const ph = document.createElement('option');
    ph.value = '';
    ph.textContent = placeholder || '';
    el.appendChild(ph);
    items.forEach((item) => {
      const { value, label } = mapFn(item);
      const opt = document.createElement('option');
      opt.value = value;
      opt.textContent = label;
      el.appendChild(opt);
    });
    if ([...el.options].some((o) => o.value === cur)) el.value = cur;
  }

  function fillKelasSelect(kelasList) {
    const el = $('#fKelas');
    const cur = el.value;
    el.innerHTML = '';

    const optAll = document.createElement('option');
    optAll.value = '';
    optAll.textContent = 'Semua';
    el.appendChild(optAll);

    const gTingkat = document.createElement('optgroup');
    gTingkat.label = 'Per tingkat';
    [
      { value: 'tingkat:X', label: 'Semua kelas X' },
      { value: 'tingkat:XI', label: 'Semua kelas XI' },
      { value: 'tingkat:XII', label: 'Semua kelas XII' },
    ].forEach(({ value, label }) => {
      const opt = document.createElement('option');
      opt.value = value;
      opt.textContent = label;
      gTingkat.appendChild(opt);
    });
    el.appendChild(gTingkat);

    const gKelas = document.createElement('optgroup');
    gKelas.label = 'Per kelas';
    (kelasList || []).forEach((nama) => {
      const opt = document.createElement('option');
      opt.value = nama;
      opt.textContent = nama;
      gKelas.appendChild(opt);
    });
    el.appendChild(gKelas);

    if ([...el.options].some((o) => o.value === cur)) el.value = cur;
  }

  function tingkatFromKelas(kelas) {
    const m = String(kelas || '').match(/^(XII|XI|X)\b/i);
    return m ? m[1].toUpperCase() : null;
  }

  function siswaForKelas(kelas) {
    const all = state.filters?.students || [];
    if (!kelas) return all;
    if (kelas.startsWith('tingkat:')) {
      const t = kelas.slice('tingkat:'.length).toUpperCase();
      return all.filter((s) =>
        (s.kelas_list || []).some((k) => tingkatFromKelas(k) === t)
      );
    }
    return all.filter((s) => (s.kelas_list || []).includes(kelas));
  }

  function fillSiswaSelect() {
    const kelas = $('#fKelas').value;
    let list = siswaForKelas(kelas);
    let placeholder = 'Semua siswa';

    // Nilai ijazah: semua siswa (nilai akhir tetap tampil meski teori belum ada)
    if (state.mode === 'nilai_ijazah') {
      placeholder = kelas
        ? (kelas.startsWith('tingkat:')
          ? `Siswa tingkat ${kelas.slice('tingkat:'.length)}`
          : `Siswa kelas ${kelas}`)
        : 'Semua siswa';
    } else if (kelas.startsWith('tingkat:')) {
      placeholder = `Siswa tingkat ${kelas.slice('tingkat:'.length)}`;
    } else if (kelas) {
      placeholder = `Siswa kelas ${kelas}`;
    }

    fillSelect($('#fSiswa'), list, placeholder, (s) => {
      const kelasLabel = (s.kelas_list || []).join('/') || '—';
      return {
        value: s.id,
        label: `${s.nama} · ${kelasLabel} · NISN ${s.nisn || s.id}`,
      };
    });
  }

  async function loadFilters() {
    const data = await api('filters');
    state.filters = data;
    applySekolahBranding(data.sekolah || { nama: data.madrasah }, data.sekolah_list || []);
    $('#importMeta').textContent = data.imported_at
      ? `Data: ${data.source || 'semua/'} · ${new Date(data.imported_at).toLocaleString('id-ID')}`
      : '';

    fillSelect($('#fTahun'), data.tahun_ajaran, 'Semua', (v) => ({ value: v, label: v }));
    fillKelasSelect(data.kelas || []);
    fillSiswaSelect();
  }

  function applySekolahBranding(aktif, list = []) {
    const nama = aktif?.nama || 'MAN 4 Sleman';
    $('#madrasahName').textContent = nama;
    document.title = `Rekap RDM — ${nama}`;

    const logo = $('#sekolahLogo');
    const mark = $('#brandMark');
    if (logo) {
      if (aktif?.logo_url) {
        logo.src = aktif.logo_url;
        logo.hidden = false;
        logo.alt = nama;
        if (mark) mark.hidden = true;
      } else {
        logo.hidden = true;
        logo.removeAttribute('src');
        if (mark) mark.hidden = false;
      }
    }

    const wrap = $('#sekolahSwitchWrap');
    const sel = $('#fSekolahAktif');
    if (wrap && sel) {
      if (can('sekolah_manage') && (list || []).length > 1) {
        wrap.hidden = false;
        const cur = aktif?.id || sel.value;
        sel.innerHTML = '';
        list.forEach((s) => {
          const opt = document.createElement('option');
          opt.value = s.id;
          opt.textContent = s.current ? `★ ${s.nama}` : s.nama;
          sel.appendChild(opt);
        });
        if ([...sel.options].some((o) => o.value === cur)) sel.value = cur;
      } else {
        wrap.hidden = true;
      }
    }
  }

  function statsHtml(ringkasan) {
    if (!ringkasan) return '';
    return `
      <div class="stats">
        <div class="stat">Siswa<strong>${fmt(ringkasan.jumlah_siswa, 0)}</strong></div>
        <div class="stat">Rata-rata<strong>${fmtRata(ringkasan.rata_avg)}</strong></div>
        <div class="stat">Rata max<strong>${fmtRata(ringkasan.rata_max)}</strong></div>
        <div class="stat">Rata min<strong>${fmtRata(ringkasan.rata_min)}</strong></div>
        <div class="stat">Jumlah avg<strong>${fmt(ringkasan.jumlah_avg, 0)}</strong></div>
      </div>`;
  }

  function perSemesterPanelHtml(g, kktpMap, sort, mode) {
    const subjects = g.subjects || [];
    const head = subjects.map((s) => sortHeader(mode, `score:${s}`, s, 'num')).join('');
    const siswa = sortSiswaRows(g.siswa || [], sort.key, sort.dir);
    const body = siswa.map((s, i) => {
      const scores = subjects.map((sub) => excelScoreCell(s.scores?.[sub], g.kelas, kktpMap, 0)).join('');
      return `<tr>
        <td class="center">${i + 1}</td>
        <td>${esc(s.nis)}</td>
        <td>${esc(s.nisn)}</td>
        <td><button type="button" class="linkish" data-open-siswa="${esc(s.id)}">${esc(s.nama)}</button></td>
        <td class="center">${esc(s.jk)}</td>
        ${scores}
        <td class="num"><strong>${fmt(s.jumlah, 0)}</strong></td>
        ${excelScoreCell(s.rata_rata, g.kelas, kktpMap, 1, { asRata: true })}
        <td class="center"><span class="badge rank">#${fmt(s.rank, 0)}</span></td>
      </tr>`;
    }).join('');

    return `<section class="panel">
      <div class="panel-head">
        <div>
          <h2>SEM ${g.semester_ke} · ${esc(g.semester)}</h2>
          <p>Tahun ajaran ${esc(g.tahun_ajaran)} · Kelas ${esc(g.kelas)} · ${siswa.length} siswa</p>
        </div>
        ${statsHtml(g.ringkasan)}
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th class="center">No</th>
              <th>NIS</th>
              <th>NISN</th>
              ${sortHeader(mode, 'nama', 'Nama')}
              <th class="center">JK</th>
              ${head}
              ${sortHeader(mode, 'jumlah', 'Jumlah', 'num')}
              ${sortHeader(mode, 'rata_rata', 'Rata', 'num')}
              ${sortHeader(mode, 'rank', 'Rank', 'center')}
            </tr>
          </thead>
          <tbody>${body}</tbody>
        </table>
      </div>
    </section>`;
  }

  function renderPerSemester(data) {
    const token = ++state.renderToken;
    if (!data.groups.length) {
      view.innerHTML = `<div class="empty">Tidak ada data untuk filter ini.</div>`;
      return;
    }

    const kktpMap = kktpNilaiMap(data);
    const sort = currentSort('per_semester');
    const mode = 'per_semester';
    const groups = data.groups;

    // Sedikit kelompok: render langsung. Banyak: bertahap agar UI tidak macet.
    if (groups.length <= 4) {
      view.innerHTML = groups.map((g) => perSemesterPanelHtml(g, kktpMap, sort, mode)).join('');
      return;
    }

    view.innerHTML = '';
    let idx = 0;
    const batch = 2;
    const paint = () => {
      if (token !== state.renderToken) return;
      const end = Math.min(idx + batch, groups.length);
      let chunk = '';
      for (; idx < end; idx++) {
        chunk += perSemesterPanelHtml(groups[idx], kktpMap, sort, mode);
      }
      view.insertAdjacentHTML('beforeend', chunk);
      if (idx < groups.length) {
        requestAnimationFrame(paint);
      }
    };
    paint();
  }

  function renderSemuaSemester(data) {
    if (!data.siswa.length) {
      view.innerHTML = `<div class="empty">Tidak ada data untuk filter ini.</div>`;
      return;
    }

    const kktpMap = kktpNilaiMap(data);
    const sort = currentSort('semua_semester');
    const mode = 'semua_semester';
    const siswa = sortSiswaRows(data.siswa || [], sort.key, sort.dir);

    const semKeys = [...new Set(
      siswa.flatMap((s) => s.semesters.map((x) => x.semester_ke))
    )].sort((a, b) => a - b);

    const head = semKeys.map((k) => sortHeader(mode, `sem:${k}`, `SEM ${k}`, 'num')).join('');

    const body = siswa.map((s) => {
      const map = Object.fromEntries(s.semesters.map((x) => [x.semester_ke, x]));
      const cells = semKeys.map((k) => {
        const row = map[k];
        if (!row) return `<td class="num">—</td>`;
        return excelScoreCell(row.rata_rata, s.kelas || row.kelas, kktpMap, 0, {
          roundInt: true,
          intervalCells: true,
        });
      }).join('');
      return `<tr>
        <td class="center"><span class="badge rank">#${s.rank_keseluruhan}</span></td>
        <td>${esc(s.nisn)}</td>
        <td><button type="button" class="linkish" data-open-siswa="${esc(s.id)}">${esc(s.nama)}</button>
          <div class="muted">${esc(s.jk)} · NIS ${esc(s.nis)}</div></td>
        <td>${esc(s.kelas)}</td>
        <td class="center">${s.semester_count}</td>
        ${cells}
        <td class="num"><strong>${fmt(s.total_jumlah, 0)}</strong></td>
        ${excelScoreCell(s.rata_rata_semua, s.kelas, kktpMap, 0, {
          roundInt: true,
          intervalCells: true,
          strong: true,
        })}
      </tr>`;
    }).join('');

    view.innerHTML = `<section class="panel">
      <div class="panel-head">
        <div>
          <h2>Rata-rata semua semester (dibulatkan)</h2>
          <p>${data.total_siswa} siswa · urut ${sort.key === 'nama' ? 'abjad nama' : 'nilai'} (${sort.dir === 'asc' ? 'naik' : 'turun'})</p>
        </div>
        <div class="panel-actions">
          ${can('export') ? '<a class="btn primary" id="btnExportSemuaSemester" href="#">Export Excel</a>' : ''}
          ${statsHtml(data.ringkasan)}
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              ${sortHeader(mode, 'rank_keseluruhan', 'Rank', 'center')}
              <th>NISN</th>
              ${sortHeader(mode, 'nama', 'Nama')}
              ${sortHeader(mode, 'kelas', 'Kelas')}
              ${sortHeader(mode, 'semester_count', '#Sem', 'center')}
              ${head}
              ${sortHeader(mode, 'total_jumlah', 'Total jumlah', 'num')}
              ${sortHeader(mode, 'rata_rata_semua', 'Rata semua', 'num')}
            </tr>
          </thead>
          <tbody>${body}</tbody>
        </table>
      </div>
    </section>`;

    const btnExport = $('#btnExportSemuaSemester');
    if (btnExport) {
      const p = new URLSearchParams();
      const f = currentFilters();
      if (f.tahun_ajaran) p.set('tahun_ajaran', f.tahun_ajaran);
      if (f.semester) p.set('semester', f.semester);
      if (f.kelas) p.set('kelas', f.kelas);
      if (f.id) p.set('id', f.id);
      btnExport.href = `unduh_rekap_semua_semester.php?${p.toString()}`;
    }
  }

  function renderPerSiswa(data) {
    if (data.error || !data.siswa) {
      view.innerHTML = `<div class="empty">${esc(data.error || 'Pilih ID siswa (NISN) untuk melihat rekap individu.')}</div>`;
      return;
    }

    const s = data.siswa;
    const hb = s.hasil_belajar;
    const chartVals = s.semesters.map((x) => x.rata_rata).filter((v) => v != null);
    const max = Math.max(...chartVals, 1);
    const min = Math.min(...chartVals, 0);
    const span = Math.max(max - min, 1);

    const bars = s.semesters.map((x) => {
      const h = x.rata_rata == null ? 8 : 18 + ((x.rata_rata - min) / span) * 54;
      return `<span style="height:${h}px" title="SEM ${x.semester_ke}: ${fmtRata(x.rata_rata)}"><i>${fmtRata(x.rata_rata)}</i></span>`;
    }).join('');
    const labels = s.semesters.map((x) => `<span>S${x.semester_ke}</span>`).join('');

    const fmtCell = (v) => {
      if (v == null || Number.isNaN(Number(v))) return '';
      return Number(v).toLocaleString('id-ID', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
      });
    };

    const slots = ['x_ganjil', 'x_genap', 'xi_ganjil', 'xi_genap', 'xii_ganjil', 'xii_genap'];
    const slotTingkat = {
      x_ganjil: 'X', x_genap: 'X',
      xi_ganjil: 'XI', xi_genap: 'XI',
      xii_ganjil: 'XII', xii_genap: 'XII',
    };
    const paiCodes = new Set(['QH', 'AA', 'FIK', 'SKI']);
    const kktpMap = kktpNilaiMap(data);

    const hasilRows = (hb?.kelompok || []).map((g) => {
      const pai = [];
      const other = [];
      (g.rows || []).forEach((r) => {
        if (paiCodes.has(r.kode) && String(g.judul).includes('Umum')) pai.push(r);
        else other.push(r);
      });
      const renderRow = (r) => {
        const vals = slots.map((k) => excelScoreCell(r.nilai?.[k], slotTingkat[k], kktpMap, 1)).join('');
        const hasTeori = r.has_teori === true || (r.ujian != null && r.ujian !== '');
        const akhirTone = hasTeori ? 'hasil-akhir hasil-akhir-teori' : 'hasil-akhir hasil-akhir-pending';
        const akhirTitle = hasTeori
          ? 'Nilai akhir (ujian teori sudah ada)'
          : 'Nilai akhir sementara — ujian teori belum ada';
        const tingkatRataan = parseTingkatKelas(hb.kelas_akhir || s.kelas_akhir || s.kelas_list?.[s.kelas_list.length - 1] || '') || 'XII';
        return `<tr>
          <td>${esc(r.nama)}</td>
          ${vals}
          ${excelScoreCell(r.rataan, tingkatRataan, kktpMap, 1, { asRata: true, strong: true })}
          <td class="num">${esc(fmtCell(r.ujian_praktek))}</td>
          <td class="num">${esc(fmtCell(r.ujian))}</td>
          <td class="num ${akhirTone}" title="${akhirTitle}"><strong>${esc(r.nilai_akhir == null || Number.isNaN(Number(r.nilai_akhir)) ? '—' : fmt(r.nilai_akhir, 0))}</strong></td>
        </tr>`;
      };
      let body = `<tr class="hasil-group"><td colspan="11">${esc(g.judul)}</td></tr>`;
      if (pai.length) {
        body += `<tr class="hasil-sub"><td colspan="11">Pendidikan Agama Islam dan Budi Pekerti</td></tr>`;
        body += pai.map(renderRow).join('');
      }
      body += other.map(renderRow).join('');
      return body;
    }).join('');

    const previewId = encodeURIComponent(s.nisn || s.id);
    const hasilHtml = hb ? `
      <section class="panel hasil-belajar-panel">
        <div class="panel-head">
          <div>
            <h2>Preview Rekap Hasil Belajar</h2>
            <p>Rata-rata rapor + nilai ujian praktek + nilai ujian → nilai akhir (bobot ijazah).</p>
          </div>
          <div class="panel-actions">
            <a class="btn primary" href="preview_rekap_siswa.php?id=${previewId}" target="_blank" rel="noopener">Buka preview cetak</a>
            <a class="btn ghost" href="preview_rekap_siswa.php?id=${previewId}&print=1" target="_blank" rel="noopener">Cetak / PDF</a>
          </div>
        </div>
        <div class="hasil-identitas">
          <div><span>NAMA</span><strong>${esc(s.nama)}</strong></div>
          <div><span>Madrasah</span><strong>${esc(hb.madrasah || '')}</strong></div>
          <div><span>NIS</span><strong>${esc(hb.nis || s.nis_list?.join(', ') || '—')}</strong></div>
          <div><span>NISN</span><strong>${esc(hb.nisn || s.nisn || s.id)}</strong></div>
        </div>
        <div class="table-wrap table-wrap-hasil">
          <table class="hasil-table">
            <thead>
              <tr>
                <th rowspan="2">Mata Pelajaran</th>
                <th colspan="2">X</th>
                <th colspan="2">XI</th>
                <th colspan="2">XII</th>
                <th rowspan="2">Rata-rata</th>
                <th rowspan="2">Nilai Ujian Praktek</th>
                <th rowspan="2">Nilai Ujian</th>
                <th rowspan="2">Nilai Akhir</th>
              </tr>
              <tr>
                <th>Ganjil</th><th>Genap</th>
                <th>Ganjil</th><th>Genap</th>
                <th>Ganjil</th><th>Genap</th>
              </tr>
            </thead>
            <tbody>
              ${hasilRows}
              <tr class="hasil-jumlah">
                <td colspan="7" class="num">Jumlah</td>
                <td class="num"><strong>${esc(fmtCell(hb.jumlah_rataan))}</strong></td>
                <td></td><td></td>
                <td class="num hasil-akhir"><strong>${esc(fmtCell(hb.jumlah_akhir))}</strong></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="muted" style="margin:0.65rem 0 0">
          Bobot nilai akhir: rapor ${esc(hb.bobot?.rataan ?? 60)}% · praktek ${esc(hb.bobot?.praktek ?? 20)}% · ujian ${esc(hb.bobot?.teori ?? 20)}%.
        </p>
      </section>` : '';

    const semPanels = s.semesters.map((sem) => {
      const subjects = sem.subjects || Object.keys(sem.scores || {});
      const rows = subjects.map((sub) => {
        const cell = excelScoreCell(sem.scores?.[sub], sem.kelas, kktpMap, 0);
        // excelScoreCell returns <td>…</td>; wrap as mapel row
        return `<tr><td>${esc(sub)}</td>${cell}</tr>`;
      }).join('');
      return `<section class="panel">
        <div class="panel-head">
          <div>
            <h2>SEM ${sem.semester_ke} · ${esc(sem.semester)}</h2>
            <p>${esc(sem.tahun_ajaran)} · Kelas ${esc(sem.kelas)}</p>
          </div>
          <div class="stats">
            <div class="stat">Jumlah<strong>${fmt(sem.jumlah, 0)}</strong></div>
            <div class="stat">Rata-rata<strong>${fmtRata(sem.rata_rata)}</strong></div>
            <div class="stat">Rank<strong>${sem.rank == null ? '—' : '#' + fmt(sem.rank, 0)}</strong></div>
            <div class="stat">Mapel<strong>${sem.mapel_count}</strong></div>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Mata pelajaran</th><th class="num">Nilai</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </section>`;
    }).join('');

    const trendRows = Object.entries(s.subject_trend || {})
      .sort((a, b) => (b[1].rata ?? -1) - (a[1].rata ?? -1))
      .map(([name, t]) => {
        const series = t.series.map((x) => `S${x.semester_ke}:${x.nilai == null ? '—' : fmt(x.nilai, 0)}`).join(' · ');
        const tingkatTrend = parseTingkatKelas(s.kelas_akhir || s.kelas_list?.[s.kelas_list.length - 1] || '') || '';
        return `<tr>
          <td>${esc(name)}</td>
          ${excelScoreCell(t.rata, tingkatTrend, kktpMap, 1, { asRata: true })}
          <td class="num">${fmt(t.max, 0)}</td>
          <td class="num">${fmt(t.min, 0)}</td>
          <td class="muted">${esc(series)}</td>
        </tr>`;
      }).join('');

    view.innerHTML = `
      <div class="student-hero panel">
        <div class="card-soft">
          <h3>${esc(s.nama)}</h3>
          <dl class="kv">
            <dt>NISN / ID</dt><dd>${esc(s.nisn || s.id)}</dd>
            <dt>NIS</dt><dd>${esc(s.nis_list.join(', ') || '—')}</dd>
            <dt>JK</dt><dd>${esc(s.jk || '—')}</dd>
            <dt>Kelas</dt><dd>${esc(s.kelas_list.join(' → '))}</dd>
            <dt>Semester</dt><dd>${s.semester_count} semester</dd>
            <dt>Total jumlah</dt><dd>${fmt(s.total_jumlah, 0)}</dd>
            <dt>Rata semua</dt><dd>${fmtRata(s.rata_rata_semua)}</dd>
          </dl>
        </div>
        <div class="card-soft">
          <strong>Tren rata-rata per semester</strong>
          <div class="spark">${bars}</div>
          <div class="spark-labels">${labels}</div>
        </div>
      </div>
      ${hasilHtml}
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>Ringkasan mapel lintas semester</h2>
            <p>Rata-rata, nilai tertinggi, dan terendah per mata pelajaran</p>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Mapel</th>
                <th class="num">Rata</th>
                <th class="num">Max</th>
                <th class="num">Min</th>
                <th>Per semester</th>
              </tr>
            </thead>
            <tbody>${trendRows}</tbody>
          </table>
        </div>
      </section>
      ${semPanels}`;
  }

  function renderKelolaKelas(data) {
    const list = data.kelas || [];
    const rows = list.map((k, i) => {
      const sumber = k.sumber === 'excel'
        ? '<span class="badge">Excel</span>'
        : '<span class="badge rank">Manual</span>';
      const hapus = k.bisa_hapus
        ? `<button type="button" class="btn ghost btn-sm" data-hapus-kelas="${esc(k.id)}">Hapus</button>`
        : '<span class="muted">—</span>';
      return `<tr>
        <td class="center">${i + 1}</td>
        <td><strong>${esc(k.nama)}</strong></td>
        <td>${esc(k.tingkat || '—')}</td>
        <td>${esc(k.tahun_ajaran || '—')}</td>
        <td>${esc(k.keterangan || '—')}</td>
        <td>${sumber}</td>
        <td class="center">${hapus}</td>
      </tr>`;
    }).join('') || `<tr><td colspan="7" class="center muted">Belum ada kelas.</td></tr>`;

    view.innerHTML = `
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>Tambah kelas</h2>
            <p>Kelas manual akan muncul di filter dropdown. Kelas dari Excel tidak bisa dihapus.</p>
          </div>
        </div>
        <form id="formKelas" class="kelas-form" autocomplete="off">
          <label>
            <span>Nama kelas *</span>
            <input type="text" name="nama" id="kNama" required maxlength="40" placeholder="Contoh: X.A" />
          </label>
          <label>
            <span>Tingkat</span>
            <select name="tingkat" id="kTingkat">
              <option value="">Otomatis</option>
              <option value="X">X</option>
              <option value="XI">XI</option>
              <option value="XII">XII</option>
            </select>
          </label>
          <label>
            <span>Tahun ajaran</span>
            <input type="text" name="tahun_ajaran" id="kTahun" maxlength="9" placeholder="2025/2026" />
          </label>
          <label class="grow">
            <span>Keterangan</span>
            <input type="text" name="keterangan" id="kKet" maxlength="120" placeholder="Opsional" />
          </label>
          <div class="filter-actions">
            <button type="submit" class="btn primary">Simpan kelas</button>
          </div>
        </form>
      </section>
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>Daftar kelas</h2>
            <p>${list.length} kelas terdaftar</p>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="center">No</th>
                <th>Nama</th>
                <th>Tingkat</th>
                <th>Tahun ajaran</th>
                <th>Keterangan</th>
                <th>Sumber</th>
                <th class="center">Aksi</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </section>`;
  }

  function ujianJenisFromMode() {
    if (state.mode === 'ujian_praktek') return 'praktek';
    if (state.mode === 'ujian_teori') return 'teori';
    return '';
  }

  function isUjianMode() {
    return state.mode === 'ujian_praktek' || state.mode === 'ujian_teori';
  }

  function optionList(items, selected = '') {
    return items.map((v) => {
      const val = typeof v === 'string' ? v : v.value;
      const label = typeof v === 'string' ? v : v.label;
      const sel = String(val) === String(selected) ? ' selected' : '';
      return `<option value="${esc(val)}"${sel}>${esc(label)}</option>`;
    }).join('');
  }

  /** Dropdown kelas ujian: per tingkat + per kelas. */
  function ujianKelasOptions(selected = '') {
    const kelasList = state.filters?.kelas || [];
    const tingkat = [
      { value: 'tingkat:X', label: 'Semua kelas X' },
      { value: 'tingkat:XI', label: 'Semua kelas XI' },
      { value: 'tingkat:XII', label: 'Semua kelas XII' },
    ];
    const sel = (v) => (String(v) === String(selected) ? ' selected' : '');
    let html = '<option value="">Pilih kelas / tingkat</option>';
    html += '<optgroup label="Per tingkat">';
    tingkat.forEach(({ value, label }) => {
      html += `<option value="${esc(value)}"${sel(value)}>${esc(label)}</option>`;
    });
    html += '</optgroup>';
    html += '<optgroup label="Per kelas">';
    kelasList.forEach((nama) => {
      html += `<option value="${esc(nama)}"${sel(nama)}>${esc(nama)}</option>`;
    });
    html += '</optgroup>';
    return html;
  }

  function kelasFilterLabel(kelas) {
    const v = String(kelas || '');
    if (v.startsWith('tingkat:')) {
      return `Semua kelas ${v.slice('tingkat:'.length)}`;
    }
    return v || '—';
  }

  function renderUjianList(data, jenis) {
    const list = data.ujian || [];
    const templates = data.templates || {};
    const jenisMeta = (templates.jenis || {})[jenis] || { nama: jenis, warna: '#0f5c45' };
    const mapelMap = templates.mapel || {};
    const tahunOpts = optionList(state.filters?.tahun_ajaran || []);
    const f = currentFilters();
    const preKelas = f.kelas || '';

    const rows = list.map((u, i) => {
      const nSiswa = (u.siswa || []).length;
      const filled = (u.siswa || []).filter((s) => s.nilai_akhir != null && s.nilai_akhir !== '').length;
      const mapelLabel = u.mapel_nama || mapelMap[u.mapel] || u.mapel || '—';
      return `<tr>
        <td class="center">${i + 1}</td>
        <td>
          <strong style="color:${esc(u.warna || jenisMeta.warna || '#0f5c45')}">${esc(u.judul)}</strong>
          <div class="muted">${esc(mapelLabel)}</div>
        </td>
        <td>${esc(kelasFilterLabel(u.kelas))}</td>
        <td>${esc(u.tahun_ajaran || '—')}</td>
        <td>${esc(u.semester || '—')}</td>
        <td>${esc(u.tanggal || '—')}</td>
        <td class="center">${filled}/${nSiswa}</td>
        <td class="aksi">
          <button type="button" class="btn ghost btn-sm" data-ujian-open="${esc(u.id)}">Nilai</button>
          <a class="btn ghost btn-sm" href="template_ujian.php?id=${encodeURIComponent(u.id)}" target="_blank" rel="noopener">Cetak</a>
          <button type="button" class="btn ghost btn-sm" data-ujian-hapus="${esc(u.id)}">Hapus</button>
        </td>
      </tr>`;
    }).join('') || `<tr><td colspan="8" class="center muted">Belum ada ${esc(jenisMeta.nama)}. Pilih mapel, unduh template, isi nilai, lalu impor.</td></tr>`;

    view.innerHTML = `
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2 style="color:${esc(jenisMeta.warna || '#0f5c45')}">Template ${esc(jenisMeta.nama)}</h2>
            <p>Pilih <strong>per tingkat</strong> (semua kelas X/XI/XII) atau <strong>per kelas</strong>. Daftar siswa &amp; mapel menyesuaikan pilihan.</p>
          </div>
        </div>
        <form id="formUjianTemplate" class="ujian-form" data-jenis="${esc(jenis)}" autocomplete="off">
          <label>
            <span>Kelas / tingkat *</span>
            <select id="uKelas" required>
              ${ujianKelasOptions(preKelas)}
            </select>
          </label>
          <label>
            <span>Tahun ajaran</span>
            <select id="uTahun">
              <option value="">—</option>
              ${tahunOpts}
            </select>
          </label>
          <label>
            <span>Semester</span>
            <select id="uSemester">
              <option value="">—</option>
              <option value="Ganjil">Ganjil</option>
              <option value="Genap">Genap</option>
            </select>
          </label>
          <label>
            <span>Tanggal</span>
            <input type="date" id="uTanggal" value="${new Date().toISOString().slice(0, 10)}" />
          </label>
          <div class="mapel-check-block">
            <div class="mapel-check-head">
              <span>Mata pelajaran * (boleh lebih dari satu)</span>
              <label class="mapel-check-all">
                <input type="checkbox" id="uMapelAll" />
                <span>Pilih semua</span>
              </label>
            </div>
            <div class="mapel-check-list" id="uMapelList">
              <p class="muted">Pilih kelas atau tingkat untuk memuat daftar mapel rapor.</p>
            </div>
          </div>
          <label class="grow">
            <span>Keterangan</span>
            <input type="text" id="uKet" maxlength="160" placeholder="Opsional" />
          </label>
          <div class="filter-actions" style="grid-column:1/-1">
            <a class="btn primary" id="btnUnduhTemplateUjian" href="#">Unduh template Excel</a>
            <a class="btn ghost" id="btnCetakBlanko" href="#" target="_blank" rel="noopener">Cetak blanko HTML</a>
          </div>
        </form>
      </section>

      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>Impor file template</h2>
            <p>Unggah file hasil unduhan yang sudah diisi nilai. Sistem membuat/memperbarui sesi ujian per mapel terpilih.</p>
          </div>
        </div>
        <form id="formImportUjian" class="import-form" data-jenis="${esc(jenis)}">
          <input type="file" id="ujianFile" accept=".xls,.xlsx,.csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
          <button type="submit" class="btn primary">Impor Excel ujian</button>
        </form>
      </section>

      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>Daftar ${esc(jenisMeta.nama)}</h2>
            <p>${list.length} sesi ujian (satu sesi = satu mapel)</p>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="center">No</th>
                <th>Judul / Mapel</th>
                <th>Kelas</th>
                <th>Tahun</th>
                <th>Semester</th>
                <th>Tanggal</th>
                <th class="center">Nilai</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </section>`;

    if (preKelas && $('#uKelas') && [...$('#uKelas').options].some((o) => o.value === preKelas)) {
      $('#uKelas').value = preKelas;
    }
    if (f.tahun_ajaran) $('#uTahun').value = f.tahun_ajaran;
    if (f.semester) $('#uSemester').value = f.semester;

    const selectedMapel = () =>
      [...document.querySelectorAll('#uMapelList input[type="checkbox"][data-kode]:checked')]
        .map((el) => el.dataset.kode);

    const syncTemplateLinks = () => {
      const kelas = $('#uKelas')?.value || '';
      const codes = selectedMapel();
      const p = new URLSearchParams({
        jenis,
        kelas,
        tahun_ajaran: $('#uTahun')?.value || '',
        semester: $('#uSemester')?.value || '',
        tanggal: $('#uTanggal')?.value || '',
        keterangan: $('#uKet')?.value || '',
      });
      codes.forEach((kode) => p.append('mapel[]', kode));
      const unduh = $('#btnUnduhTemplateUjian');
      if (unduh) {
        unduh.href = kelas && codes.length ? `unduh_template_ujian.php?${p.toString()}` : '#';
      }
      const blank = $('#btnCetakBlanko');
      if (blank) {
        blank.href = `template_ujian.php?${new URLSearchParams({
          jenis,
          blank: '1',
          kelas,
          tahun_ajaran: $('#uTahun')?.value || '',
          semester: $('#uSemester')?.value || '',
          tanggal: $('#uTanggal')?.value || '',
          mapel: codes[0] || '',
        }).toString()}`;
      }
    };

    const renderMapelChecks = (items, keepSelected = []) => {
      const box = $('#uMapelList');
      if (!box) return;
      const keep = new Set(keepSelected);
      if (!items.length) {
        box.innerHTML = `<p class="muted">Tidak ada mapel untuk kelas ini.</p>`;
        const allEl = $('#uMapelAll');
        if (allEl) allEl.checked = false;
        syncTemplateLinks();
        return;
      }
      box.innerHTML = items.map((m) => `
        <label class="mapel-check-item">
          <input type="checkbox" data-kode="${esc(m.kode)}" ${keep.has(m.kode) ? 'checked' : ''} />
          <span>${esc(m.nama)}</span>
        </label>
      `).join('');
      box.querySelectorAll('input[data-kode]').forEach((el) => {
        el.addEventListener('change', () => {
          const all = [...box.querySelectorAll('input[data-kode]')];
          const allEl = $('#uMapelAll');
          if (allEl) allEl.checked = all.length > 0 && all.every((x) => x.checked);
          syncTemplateLinks();
        });
      });
      const all = [...box.querySelectorAll('input[data-kode]')];
      const allEl = $('#uMapelAll');
      if (allEl) allEl.checked = all.length > 0 && all.every((x) => x.checked);
      syncTemplateLinks();
    };

    const loadMapelForKelas = async () => {
      const kelas = $('#uKelas')?.value || '';
      if (!kelas) {
        renderMapelChecks(
          Object.entries(mapelMap).map(([kode, nama]) => ({ kode, nama })),
          []
        );
        return;
      }
      try {
        const res = await api('mapel_kelas', {
          kelas,
          tahun_ajaran: $('#uTahun')?.value || '',
          semester: $('#uSemester')?.value || '',
        });
        const items = res.mapel || [];
        // Ujian teori: otomatis centang semua mapel rapor kelas
        // Ujian praktek: tampilkan daftar, biarkan user mencentang manual
        const autoCheck = jenis === 'teori' ? items.map((m) => m.kode) : [];
        renderMapelChecks(items, autoCheck);
      } catch (err) {
        renderMapelChecks(
          Object.entries(mapelMap).map(([kode, nama]) => ({ kode, nama })),
          []
        );
        showStatus(err.message, true);
      }
    };

    $('#uMapelAll')?.addEventListener('change', () => {
      const on = $('#uMapelAll').checked;
      document.querySelectorAll('#uMapelList input[data-kode]').forEach((el) => {
        el.checked = on;
      });
      syncTemplateLinks();
    });

    ['uKelas', 'uTahun', 'uSemester'].forEach((id) => {
      $(`#${id}`)?.addEventListener('change', () => {
        loadMapelForKelas();
      });
    });
    ['uTanggal', 'uKet'].forEach((id) => {
      const el = $(`#${id}`);
      if (!el) return;
      el.addEventListener('change', syncTemplateLinks);
      el.addEventListener('input', syncTemplateLinks);
    });

    $('#btnUnduhTemplateUjian')?.addEventListener('click', (ev) => {
      if (!$('#uKelas')?.value) {
        ev.preventDefault();
        showStatus('Pilih kelas atau tingkat terlebih dahulu.', true);
        return;
      }
      if (!selectedMapel().length) {
        ev.preventDefault();
        showStatus('Centang minimal satu mata pelajaran.', true);
      }
    });

    loadMapelForKelas();
  }

  function renderUjianDetail(ujian) {
    const warna = ujian.warna || (ujian.jenis === 'teori' ? '#1d4f91' : '#0f5c45');
    const mapelLabel = ujian.mapel_nama || ujian.mapel || '—';
    const body = (ujian.siswa || []).map((s, i) => `<tr data-row="${i}">
      <td class="center">${i + 1}</td>
      <td>${esc(s.nis)}</td>
      <td>${esc(s.nisn)}</td>
      <td class="nama">${esc(s.nama)}</td>
      <td class="center">${esc(s.jk)}</td>
      <td class="num">
        <input class="nilai-input" type="number" min="0" max="100" step="0.01" data-row="${i}" value="${s.nilai_akhir == null ? '' : esc(s.nilai_akhir)}" />
      </td>
      <td><input class="catatan-input" type="text" data-row="${i}" value="${esc(s.catatan || '')}" maxlength="80" /></td>
    </tr>`).join('');

    const jenisLabel = ujian.jenis === 'teori' ? 'Ujian teori' : 'Ujian praktek';

    view.innerHTML = `
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2 style="color:${esc(warna)}">${esc(ujian.judul)}</h2>
            <p>${esc(jenisLabel)} · ${esc(mapelLabel)} · ${esc(kelasFilterLabel(ujian.kelas))} · ${esc(ujian.tahun_ajaran || '—')} · ${esc(ujian.semester || '—')}</p>
          </div>
          <div class="filter-actions">
            <button type="button" class="btn ghost" data-ujian-back>Kembali</button>
            <a class="btn ghost" href="template_ujian.php?id=${encodeURIComponent(ujian.id)}" target="_blank" rel="noopener">Cetak</a>
            <a class="btn ghost" href="template_ujian.php?id=${encodeURIComponent(ujian.id)}&blank=1" target="_blank" rel="noopener">Cetak blanko</a>
            <button type="button" class="btn primary" data-ujian-save="${esc(ujian.id)}">Simpan nilai</button>
          </div>
        </div>
        <div class="table-wrap" id="ujianNilaiWrap" data-ujian-id="${esc(ujian.id)}">
          <table>
            <thead>
              <tr>
                <th class="center">No</th>
                <th>NIS</th>
                <th>NISN</th>
                <th>Nama</th>
                <th class="center">JK</th>
                <th class="num">Nilai akhir</th>
                <th>Catatan</th>
              </tr>
            </thead>
            <tbody>${body}</tbody>
          </table>
        </div>
      </section>`;

    state.ujianDetail = ujian;
  }

  function collectNilaiFromTable() {
    const ujian = state.ujianDetail;
    if (!ujian) return [];
    return (ujian.siswa || []).map((s, i) => {
      const input = view.querySelector(`.nilai-input[data-row="${i}"]`);
      const catatanEl = view.querySelector(`.catatan-input[data-row="${i}"]`);
      const raw = input ? input.value.trim() : '';
      return {
        nis: s.nis,
        nisn: s.nisn,
        nama: s.nama,
        jk: s.jk,
        nilai_akhir: raw === '' ? null : Number(raw),
        catatan: catatanEl ? catatanEl.value.trim() : '',
      };
    });
  }

  function syncBobotRaporAuto() {
    const praktek = Number($('#bPraktek')?.value || 0);
    const teori = Number($('#bTeori')?.value || 0);
    const rapor = Math.round((100 - praktek - teori) * 100) / 100;
    const el = $('#bRataan');
    const display = $('#bRataanDisplay');
    if (el) el.value = String(rapor);
    if (display) {
      display.textContent = `${rapor}%`;
      display.classList.toggle('bobot-invalid', rapor < 0);
    }
    const hint = $('#bobotHintAuto');
    if (hint) {
      hint.textContent = rapor < 0
        ? 'Total praktek + teori melebihi 100%. Kurangi salah satu.'
        : `Nilai ijazah = (rataan × ${rapor}%) + (praktek × ${praktek}%) + (teori × ${teori}%). Rapor terkunci: 100 − praktek − teori.`;
    }
  }

  function bobotFormHtml(bobot) {
    const b = bobot || { rataan: 60, praktek: 20, teori: 20 };
    const raporAuto = Math.round((100 - Number(b.praktek) - Number(b.teori)) * 100) / 100;
    if (!can('bobot_ijazah')) {
      return `<p class="muted bobot-hint">Bobot nilai ijazah: rapor ${esc(b.rataan)}% · praktek ${esc(b.praktek)}% · teori ${esc(b.teori)}%.</p>`;
    }
    return `
      <form id="formBobotIjazah" class="bobot-form" autocomplete="off">
        <div class="bobot-title">Bobot nilai ijazah</div>
        <label>
          <span>Rataan rapor % <em class="bobot-auto-tag">(terkunci)</em></span>
          <input type="hidden" id="bRataan" value="${esc(raporAuto)}" />
          <div id="bRataanDisplay" class="bobot-locked ${raporAuto < 0 ? 'bobot-invalid' : ''}" aria-live="polite">${esc(raporAuto)}%</div>
        </label>
        <label>
          <span>Ujian praktek %</span>
          <input type="number" id="bPraktek" min="0" max="100" step="1" value="${esc(b.praktek)}" required />
        </label>
        <label>
          <span>Ujian teori %</span>
          <input type="number" id="bTeori" min="0" max="100" step="1" value="${esc(b.teori)}" required />
        </label>
        <div class="filter-actions">
          <button type="submit" class="btn primary">Simpan bobot</button>
        </div>
        <p class="muted bobot-hint" id="bobotHintAuto">Nilai ijazah = (rataan × ${esc(raporAuto)}%) + (praktek × ${esc(b.praktek)}%) + (teori × ${esc(b.teori)}%). Rapor terkunci: 100 − praktek − teori.</p>
      </form>`;
  }

  function renderIjazahDaftar(data) {
    const angkatanList = data.angkatan || [];
    const kktpMap = kktpNilaiMap(data);

    const scoreCell = (v, opts = {}) => {
      const digits = opts.digits ?? 1;
      const tdClass = opts.tdClass || 'num mapel-col';
      if (v == null || Number.isNaN(Number(v))) {
        return `<td class="${tdClass}"><span class="score-empty">—</span></td>`;
      }
      const n = Math.round(Number(v) * (digits === 0 ? 1 : 10)) / (digits === 0 ? 1 : 10);
      const nShow = digits === 0 ? Math.round(Number(v)) : n;
      const thr = (() => {
        const kelas = opts.kelas || '';
        const key = String(kelas).toUpperCase();
        if (kktpMap && Object.prototype.hasOwnProperty.call(kktpMap, key)) return kktpMap[key];
        const tingkat = parseTingkatKelas(kelas);
        return tingkat != null && kktpMap ? kktpMap[tingkat] : null;
      })();
      const band = thr != null ? kktpPredikatBand(nShow, thr) : null;
      const below = band === 'belum';
      const hasTeori = opts.hasTeori === true;
      const forMapel = opts.forMapel === true;
      let tone = 'plain';
      if (below) {
        tone = 'below-kktp';
      } else if (forMapel && hasTeori) {
        if (band === 'sangat_baik') tone = 'plain band-sb';
        else if (band === 'baik') tone = 'plain band-baik';
        else tone = 'plain band-cukup';
      } else if (forMapel && !hasTeori) {
        tone = 'plain teori-pending';
      } else if (opts.intervalAlways && band) {
        if (band === 'sangat_baik') tone = 'plain band-sb';
        else if (band === 'baik') tone = 'plain band-baik';
        else if (band === 'cukup') tone = 'plain band-cukup';
      }
      const title = below
        ? `Di bawah KKTP (${thr})`
        : (forMapel
          ? (hasTeori ? `Nilai akhir · ${band === 'sangat_baik' ? 'Sangat Baik' : band === 'baik' ? 'Baik' : 'Cukup'}` : 'Nilai sementara — ujian teori belum ada')
          : (thr != null ? `KKTP: ${thr}` : ''));
      const titleAttr = title ? ` title="${esc(title)}"` : '';
      const text = Number(nShow).toLocaleString('id-ID', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
      });
      return `<td class="${tdClass}"><span class="score-pill ${tone}"${titleAttr}>${text}</span></td>`;
    };

    const rankBadge = (rank) => {
      const top = rank <= 3 ? ` top${rank}` : '';
      return `<span class="rank-pill${top}">${rank}</span>`;
    };

    const sort = currentSort('nilai_ijazah');
    const mode = 'nilai_ijazah';

    const renderKelompok = (g, gi, angkatanLabel) => {
      const mapelCols = g.mapel || [];
      const rataMapel = g.rata_mapel || {};
      const colCount = 5 + mapelCols.length + 1;
      const headMapel = mapelCols.map((m) =>
        sortHeader(mode, `mapel:${m.kode}`, m.short || m.kode, 'num mapel-col', m.nama || m.kode)
      ).join('');

      const siswa = sortSiswaRows(g.siswa || [], sort.key, sort.dir);
      const body = siswa.map((s, si) => {
        const nm = s.nilai_mapel || {};
        const ht = s.has_teori_mapel || {};
        const kelas = s.kelas_akhir || '';
        const mapelCells = mapelCols.map((m) => scoreCell(nm[m.kode], {
          forMapel: true,
          hasTeori: ht[m.kode] === true,
          kelas,
          digits: 0,
        })).join('');
        return `<tr class="${si % 2 ? 'row-alt' : ''}">
          <td class="center">${rankBadge(s.rank)}</td>
          <td class="nisn-cell">${esc(s.nisn)}</td>
          <td class="nama-cell">
            <button type="button" class="linkish" data-ijazah-siswa="${esc(s.id)}">${esc(s.nama)}</button>
            <div class="muted meta-line">${esc(s.jk)} · ${esc(s.kelas_akhir || '—')}</div>
          </td>
          <td><span class="kelas-tag">${esc(s.kelas_akhir || '—')}</span></td>
          ${mapelCells}
          ${scoreCell(s.rata_ijazah, { kelas, tdClass: 'num rata-cell', digits: 0, intervalAlways: true })}
        </tr>`;
      }).join('') || `<tr><td colspan="${colCount}" class="center muted">Tidak ada siswa.</td></tr>`;

      const footMapel = mapelCols.map((m) => scoreCell(rataMapel[m.kode], { digits: 0 })).join('');
      const avgIjazah = (() => {
        const vals = (g.siswa || []).map((s) => s.rata_ijazah).filter((v) => v != null);
        if (!vals.length) return null;
        return Math.round(vals.reduce((a, b) => a + Number(b), 0) / vals.length);
      })();

      return `
        <section class="panel ijazah-kelompok">
          <div class="ijazah-group-banner">
            <div>
              <p class="ijazah-group-kicker">${esc(angkatanLabel)} · Kelompok mapel ${gi + 1}</p>
              <h2>${esc(g.mapel_count)} mapel · ${esc(g.total_siswa)} siswa</h2>
              <p>Satu NISN hanya sekali per angkatan. Font hitam; merah jika di bawah KKTP.</p>
            </div>
            <div class="ijazah-group-meta">
              <span>${esc(g.mapel_count)} mapel</span>
              <span>${esc(g.total_siswa)} siswa</span>
            </div>
          </div>
          <div class="table-wrap table-wrap-ijazah">
            <table class="ijazah-table">
              <thead>
                <tr>
                  ${sortHeader(mode, 'rank', '#', 'center')}
                  <th>NISN</th>
                  ${sortHeader(mode, 'nama', 'Nama siswa')}
                  ${sortHeader(mode, 'kelas_akhir', 'Kelas')}
                  ${headMapel}
                  ${sortHeader(mode, 'rata_ijazah', 'Rata', 'num')}
                </tr>
              </thead>
              <tbody>${body}</tbody>
              <tfoot>
                <tr class="ijazah-avg-row">
                  <td class="center" colspan="3"><span class="avg-label">Rata-rata mapel</span></td>
                  <td></td>
                  ${footMapel}
                  ${scoreCell(avgIjazah, { digits: 0, tdClass: 'num rata-cell' })}
                </tr>
              </tfoot>
            </table>
          </div>
        </section>`;
    };

    const angkatanHtml = angkatanList.map((a) => {
      const groups = (a.kelompok || []).map((g, gi) => renderKelompok(g, gi, a.label)).join('');
      return `
        <div class="ijazah-angkatan">
          <div class="ijazah-angkatan-head">
            <h2>${esc(a.label)}</h2>
            <p>${esc(a.total_siswa)} siswa · ${esc(a.total_kelompok)} kelompok mapel</p>
          </div>
          ${groups || '<div class="empty">Tidak ada data.</div>'}
        </div>`;
    }).join('') || `
      <section class="panel">
        <div class="empty">Tidak ada data siswa untuk filter ini.</div>
      </section>`;

    view.innerHTML = `
      <section class="panel ijazah-hero">
        <div class="panel-head">
          <div>
            <h2 class="ijazah-title">Nilai ijazah</h2>
            <p>Per angkatan, dikelompokkan mapel yang sama. Font hitam; merah jika di bawah KKTP. Latar putih/biru/hijau hanya jika teori sudah ada.</p>
          </div>
          <div class="panel-actions">
            <a class="btn primary" id="btnExportIjazah" href="#">Export Excel</a>
          </div>
        </div>
        ${bobotFormHtml(data.bobot)}
        <div class="stats" style="margin:0.75rem 0">
          <div class="stat">Angkatan<strong>${fmt(data.total_angkatan ?? angkatanList.length, 0)}</strong></div>
          <div class="stat">Kelompok<strong>${fmt(data.total_kelompok, 0)}</strong></div>
          <div class="stat">Siswa<strong>${fmt(data.total_siswa, 0)}</strong></div>
          <div class="stat">Bobot rapor<strong>${esc(data.bobot.rataan)}%</strong></div>
          <div class="stat">Bobot ujian<strong>${esc(Number(data.bobot.praktek) + Number(data.bobot.teori))}%</strong></div>
        </div>
      </section>
      ${angkatanHtml}`;

    const btnExport = $('#btnExportIjazah');
    if (btnExport) {
      const p = new URLSearchParams();
      const f = currentFilters();
      if (f.kelas) p.set('kelas', f.kelas);
      if (f.tahun_ajaran) p.set('tahun_ajaran', f.tahun_ajaran);
      if (f.semester) p.set('semester', f.semester);
      btnExport.href = `unduh_nilai_ijazah.php?${p.toString()}`;
    }
  }

  function renderIjazahDetail(data) {
    const s = data.siswa;
    if (!s) {
      view.innerHTML = `<div class="empty">Data siswa tidak ditemukan.</div>`;
      return;
    }
    const kktpMap = kktpNilaiMap(data);
    const tingkatSiswa = s.kelas_akhir || (s.kelas_list || []).slice(-1)[0] || '';
    const body = (s.mapel || []).map((m, i) => {
      const ket = (m.keterangan || '').trim();
      const ketHtml = ket
        ? `<span class="ket-warn" title="${esc(ket)}">${esc(ket)}</span>`
        : `<span class="muted">${m.semester_count || 0} smt</span>`;
      const ij = m.nilai_ijazah;
      const hasTeori = m.ujian_teori != null && m.ujian_teori !== '';
      const thrKey = String(tingkatSiswa || '').toUpperCase();
      const tingkat = (kktpMap && Object.prototype.hasOwnProperty.call(kktpMap, thrKey))
        ? thrKey
        : parseTingkatKelas(tingkatSiswa);
      const thr = tingkat != null && kktpMap ? kktpMap[tingkat] : null;
      const ijRounded = ij == null ? null : Math.round(Number(ij));
      const band = ijRounded != null && thr != null ? kktpPredikatBand(ijRounded, thr) : null;
      const below = band === 'belum';
      let tone = '';
      if (ij != null) {
        if (below) tone = 'below-kktp';
        else if (hasTeori) {
          if (band === 'sangat_baik') tone = 'plain band-sb';
          else if (band === 'baik') tone = 'plain band-baik';
          else tone = 'plain band-cukup';
        } else {
          tone = 'plain teori-pending';
        }
      }
      const title = ij == null
        ? ''
        : (below
          ? `Di bawah KKTP (${thr})`
          : (hasTeori
            ? `Nilai akhir · ${band === 'sangat_baik' ? 'Sangat Baik' : band === 'baik' ? 'Baik' : 'Cukup'}`
            : 'Nilai sementara — ujian teori belum ada'));
      return `<tr class="${i % 2 ? 'row-alt' : ''}">
      <td class="center"><span class="rank-pill">${i + 1}</span></td>
      <td><strong>${esc(m.kode)}</strong><div class="muted">${esc(m.nama)}</div></td>
      <td class="num">${m.jumlah == null ? '—' : fmt(m.jumlah)}</td>
      ${excelScoreCell(m.rataan, tingkatSiswa, kktpMap, 1, { asRata: true, plainKktp: true })}
      <td class="num">${m.ujian_praktek == null ? '—' : fmt(m.ujian_praktek)}</td>
      <td class="num">${m.ujian_teori == null ? '—' : fmt(m.ujian_teori)}</td>
      <td class="num">${ij == null ? '<span class="score-empty">—</span>' : `<span class="score-pill ${tone} total" title="${esc(title)}">${fmt(ijRounded, 0)}</span>`}</td>
      <td class="ket-cell">${ketHtml}</td>
    </tr>`;
    }).join('');

    view.innerHTML = `
      <section class="panel ijazah-kelompok">
        <div class="ijazah-group-banner">
          <div>
            <p class="ijazah-group-kicker">Detail siswa</p>
            <h2>${esc(s.nama)}</h2>
            <p>NISN ${esc(s.nisn)} · ${(s.kelas_list || []).join(' → ')} · ${s.semester_count} semester (X–XII)</p>
          </div>
          <div class="filter-actions">
            <a class="btn primary" id="btnExportIjazahDetail" href="#">Export Excel</a>
            <button type="button" class="btn ghost" data-ijazah-back>Kembali</button>
          </div>
        </div>
        ${bobotFormHtml(data.bobot)}
        <div class="stats" style="margin:0.75rem 0">
          <div class="stat">Mapel<strong>${fmt(s.ringkasan.mapel_count, 0)}</strong></div>
          <div class="stat">Rata rataan<strong>${fmtRata(s.ringkasan.rata_rataan)}</strong></div>
          <div class="stat">Rata praktek<strong>${fmtRata(s.ringkasan.rata_praktek)}</strong></div>
          <div class="stat">Rata teori<strong>${fmtRata(s.ringkasan.rata_teori)}</strong></div>
          <div class="stat">Rata ijazah<strong>${fmt(s.ringkasan.rata_ijazah, 0)}</strong></div>
        </div>
        <div class="table-wrap table-wrap-ijazah">
          <table class="ijazah-table ijazah-table-detail">
            <thead>
              <tr>
                <th class="center">#</th>
                <th>Mata pelajaran</th>
                <th class="num">Jumlah</th>
                <th class="num">Rataan</th>
                <th class="num">Praktek</th>
                <th class="num">Teori</th>
                <th class="num">Ijazah</th>
                <th>Ket</th>
              </tr>
            </thead>
            <tbody>${body}</tbody>
          </table>
        </div>
      </section>`;

    const btnExport = $('#btnExportIjazahDetail');
    if (btnExport) {
      const p = new URLSearchParams({ id: s.nisn || s.id });
      btnExport.href = `unduh_nilai_ijazah.php?${p.toString()}`;
    }
  }

  function syncFilterPanel() {
    const panel = $('#filterPanel');
    if (panel) {
      panel.hidden = state.mode === 'kelola_kelas'
        || isUjianMode()
        || state.mode === 'impor_data'
        || state.mode === 'pengaturan_sekolah'
        || state.mode === 'kelola_user'
        || state.mode === 'kktp';
    }
  }

  function renderKktpIntervalPreview(kktp) {
    const iv = kktpIntervals(kktp);
    if (!iv) return '<p class="muted">Isi nilai batas ketercapaian untuk melihat interval.</p>';
    const rows = [
      ['belum', 'kktp-iv-belum'],
      ['cukup', 'kktp-iv-cukup'],
      ['baik', 'kktp-iv-baik'],
      ['sangat_baik', 'kktp-iv-sb'],
    ].map(([key, cls]) => {
      const r = iv[key];
      return `<li class="${cls}"><strong>${esc(r.label)}</strong>
        <span>${fmtIntervalBound(r.min)} – ${fmtIntervalBound(r.max)}</span></li>`;
    }).join('');
    return `<div class="kktp-interval">
      <h3>Interval</h3>
      <p class="muted">Mengikuti Nilai Batas Ketercapaian <strong>${fmtIntervalBound(iv.kktp)}</strong>. Berubah otomatis jika KKTP diubah.</p>
      <ul class="kktp-interval-list">${rows}</ul>
    </div>`;
  }

  function bindKktpIntervalLive() {
    const box = $('#kktpIntervalBox');
    if (!box) return;
    const inputs = [...document.querySelectorAll('#formKktp input[data-tingkat]')];
    const refresh = () => {
      const vals = inputs.map((el) => Number(el.value)).filter((n) => Number.isFinite(n));
      const sample = vals.length ? vals[0] : 75;
      // Jika semua tingkat sama, pakai itu; jika beda, tampilkan per tingkat ringkas
      const allSame = vals.length > 0 && vals.every((v) => v === vals[0]);
      if (allSame || vals.length <= 1) {
        box.innerHTML = renderKktpIntervalPreview(sample);
        return;
      }
      const blocks = inputs.map((el) => {
        const k = Number(el.value);
        const label = el.closest('label')?.querySelector('span')?.textContent || el.dataset.tingkat;
        return `<div class="kktp-interval-tingkat"><h4>${esc(label)}</h4>${renderKktpIntervalPreview(k)}</div>`;
      }).join('');
      box.innerHTML = blocks;
    };
    inputs.forEach((el) => el.addEventListener('input', refresh));
    refresh();
  }

  function renderKktp(data) {
    const jenjang = (data.jenjang || []).join(' · ') || '—';
    const tingkat = data.tingkat || [];
    const canEdit = can('kktp');
    const updated = data.updated_at
      ? new Date(data.updated_at).toLocaleString('id-ID')
      : 'Belum pernah disimpan';
    const sampleKktp = tingkat[0]?.nilai ?? data.default ?? 75;

    const fields = tingkat.map((t) => `
      <label class="kktp-field">
        <span>${esc(t.label)}</span>
        <input
          type="number"
          name="kktp_${esc(t.kode)}"
          data-tingkat="${esc(t.kode)}"
          min="0"
          max="100"
          step="0.01"
          value="${esc(t.nilai)}"
          ${canEdit ? '' : 'readonly'}
          required
        />
      </label>`).join('');

    view.innerHTML = `
      <section class="panel kktp-panel">
        <div class="panel-head">
          <div>
            <h2 class="kktp-title">KKTP</h2>
            <p>Kriteria Ketercapaian Tujuan Pembelajaran per tingkat. Jenjang terdeteksi dari data Excel: <strong>${esc(jenjang)}</strong>.</p>
          </div>
        </div>
        <form id="formKktp" class="kktp-form" autocomplete="off">
          <div class="kktp-grid">
            ${fields || '<p class="muted">Tidak ada tingkat terdeteksi. Unggah Excel rapor terlebih dahulu.</p>'}
          </div>
          <p class="muted kktp-hint">Nilai default ${esc(data.default ?? 75)}. Rentang 0–100. Terakhir disimpan: ${esc(updated)}</p>
          <div id="kktpIntervalBox">${renderKktpIntervalPreview(sampleKktp)}</div>
          ${canEdit && tingkat.length
            ? `<div class="filter-actions">
                <button type="submit" class="btn primary">Simpan KKTP</button>
                <button type="button" class="btn ghost" id="btnKktpResetDefault">Kembalikan ke 75</button>
              </div>`
            : (!canEdit ? '<p class="muted">Hanya admin yang dapat mengubah KKTP.</p>' : '')}
        </form>
      </section>`;

    bindKktpIntervalLive();
  }

  function renderKelolaUser(data) {
    const canSa = can('users_superadmin');
    const users = (data.users || []).filter((u) => canSa || u.role !== 'superadmin');
    const roles = data.roles || state.roles || {};
    const editId = state.userEditId;
    const edit = users.find((u) => u.id === editId) || null;
    const roleOptions = Object.entries(roles)
      .filter(([k]) => canSa || k !== 'superadmin')
      .map(([k, label]) => `<option value="${esc(k)}" ${edit && edit.role === k ? 'selected' : (!edit && k === 'user' ? 'selected' : '')}>${esc(label)}</option>`)
      .join('');

    const rows = users.map((u, i) => {
      return `<tr>
        <td class="center">${i + 1}</td>
        <td>${esc(u.username)}</td>
        <td>${esc(u.nama)}</td>
        <td><span class="badge">${esc(u.role_label || u.role)}</span></td>
        <td class="muted">${esc(u.sekolah_id || '—')}</td>
        <td class="center">${u.aktif ? '<span class="badge">Aktif</span>' : '<span class="badge rank">Nonaktif</span>'}</td>
        <td class="aksi">
          <button type="button" class="btn ghost btn-sm" data-user-edit="${esc(u.id)}">Edit</button>
          <button type="button" class="btn ghost btn-sm" data-user-del="${esc(u.id)}" ${u.id === state.user?.id ? 'disabled' : ''}>Hapus</button>
        </td>
      </tr>`;
    }).join('') || `<tr><td colspan="7" class="center muted">Belum ada pengguna.</td></tr>`;

    const roleHelp = canSa
      ? 'Kelola akun login. Role: Superadmin (penuh), Admin (kelola data), User (lihat rekap & unduh).'
      : 'Kelola akun login. Role: Admin (kelola data), User (lihat rekap & unduh).';

    view.innerHTML = `
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2 style="color:#0f5c45">${edit ? 'Edit pengguna' : 'Tambah pengguna'}</h2>
            <p>${roleHelp}</p>
          </div>
        </div>
        <form id="formUser" class="sekolah-form" autocomplete="off">
          <input type="hidden" name="id" id="userId" value="${esc(edit?.id || '')}" />
          <label>
            <span>Username ${edit ? '(tidak bisa diubah)' : ''}</span>
            <input type="text" id="userUsername" name="username" value="${esc(edit?.username || '')}" ${edit ? 'readonly' : 'required'} pattern="[a-z0-9._-]{3,40}" maxlength="40" placeholder="contoh: guru1" />
          </label>
          <label>
            <span>Nama tampilan</span>
            <input type="text" id="userNama" name="nama" value="${esc(edit?.nama || '')}" maxlength="80" placeholder="Nama lengkap" />
          </label>
          <label>
            <span>Role</span>
            <select id="userRole" name="role" required>${roleOptions}</select>
          </label>
          <label>
            <span>Status</span>
            <select id="userAktif" name="aktif">
              <option value="1" ${!edit || edit.aktif ? 'selected' : ''}>Aktif</option>
              <option value="0" ${edit && !edit.aktif ? 'selected' : ''}>Nonaktif</option>
            </select>
          </label>
          <label class="grow">
            <span>Password ${edit ? '(kosongkan jika tidak diubah)' : ''}</span>
            <input type="password" id="userPassword" name="password" ${edit ? '' : 'required'} minlength="8" maxlength="100" placeholder="${edit ? '••••••••' : 'Minimal 8 karakter'}" autocomplete="new-password" />
          </label>
          <div class="filter-actions" style="grid-column:1/-1">
            <button type="submit" class="btn primary">${edit ? 'Simpan perubahan' : 'Tambah pengguna'}</button>
            ${edit ? '<button type="button" class="btn ghost" id="btnUserBatal">Batal edit</button>' : ''}
          </div>
        </form>
      </section>

      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>Daftar pengguna</h2>
            <p>${users.length} akun terdaftar.</p>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="center">#</th>
                <th>Username</th>
                <th>Nama</th>
                <th>Role</th>
                <th>Sekolah</th>
                <th class="center">Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>Ubah password saya</h2>
            <p>Ganti password akun yang sedang login (${esc(state.user?.username || '')}).</p>
          </div>
        </div>
        <form id="formChangePassword" class="sekolah-form" autocomplete="off">
          <label>
            <span>Password lama</span>
            <input type="password" id="oldPassword" required minlength="1" autocomplete="current-password" />
          </label>
          <label>
            <span>Password baru</span>
            <input type="password" id="newPassword" required minlength="8" autocomplete="new-password" />
          </label>
          <div class="filter-actions" style="grid-column:1/-1">
            <button type="submit" class="btn primary">Ubah password</button>
          </div>
        </form>
      </section>`;
  }

  function renderImporData(data) {
    const health = data.health || {};
    const files = data.files || [];
    const rows = files.map((f, i) => `<tr>
      <td class="center">${i + 1}</td>
      <td>${esc(f.name)}</td>
      <td class="num">${esc(formatBytes(f.size))}</td>
      <td>${esc(f.mtime_label)}</td>
      <td class="aksi">
        <button type="button" class="btn ghost btn-sm" data-import-del="${esc(f.name)}">Hapus</button>
      </td>
    </tr>`).join('') || `<tr><td colspan="5" class="center muted">Belum ada file. Unggah dari komputer atau impor dari cloud.</td></tr>`;

    const ok = (v) => v
      ? '<span class="badge">OK</span>'
      : '<span class="badge rank">Perlu perbaikan</span>';

    view.innerHTML = `
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2 style="color:#0f5c45">Impor data Excel</h2>
            <p>Jalankan di hosting dengan mengunggah file dari komputer atau menempel tautan cloud (Google Drive / Dropbox / URL langsung).</p>
          </div>
        </div>
        <div class="stats">
          <div class="stat">File Excel<strong>${fmt(health.xlsx_count || files.length, 0)}</strong></div>
          <div class="stat">Folder sumber${ok(health.source_writable)}</div>
          <div class="stat">Folder data${ok(health.data_writable)}</div>
          <div class="stat">Database${ok(health.db_ok)}</div>
        </div>
        ${health.db_error ? `<div class="status error" style="margin-top:0.75rem">DB: ${esc(health.db_error)} — aplikasi tetap bisa memakai JSON fallback.</div>` : ''}
        <p class="muted" style="margin-top:0.75rem">Path sumber: <code>${esc(health.source_dir || '')}</code> · Batas upload: ${esc(health.upload_max_mb || 20)} MB · PHP upload_max: ${esc(health.php_upload_max_filesize || '—')} · max_file_uploads: ${esc(health.php_max_file_uploads || 20)}</p>
      </section>

      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>1. Dari komputer</h2>
            <p>Pilih satu atau banyak file <code>.xlsx</code> legger nilai. Unggahan otomatis dibagi per batch (batas PHP biasanya 20 file/request).</p>
          </div>
        </div>
        <form id="formUploadLocal" class="import-form">
          <input type="file" id="localFiles" name="files[]" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple required />
          <button type="submit" class="btn primary">Unggah & sinkronkan</button>
        </form>
      </section>

      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>2. Dari cloud / URL</h2>
            <p>Tempel tautan publik Google Drive, Dropbox, OneDrive, atau URL langsung file .xlsx.</p>
          </div>
        </div>
        <form id="formImportCloud" class="import-form cloud">
          <label class="grow">
            <span>URL cloud</span>
            <input type="url" id="cloudUrl" required placeholder="https://drive.google.com/file/d/.../view atau https://..." />
          </label>
          <label>
            <span>Nama file (opsional)</span>
            <input type="text" id="cloudName" maxlength="120" placeholder="LEGGER NILAI KELAS X.A.xlsx" />
          </label>
          <div class="filter-actions">
            <button type="submit" class="btn primary">Impor dari cloud</button>
          </div>
        </form>
        <p class="muted">Google Drive: set sharing “Anyone with the link”. Dropbox: gunakan link share lalu aplikasi mengubah ke unduhan langsung.</p>
      </section>

      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>File di folder sumber</h2>
            <p>${files.length} file · setelah unggah/impor data otomatis disinkronkan</p>
          </div>
          <div class="filter-actions">
            <button type="button" class="btn ghost" id="btnSyncImport">Sinkronkan ulang</button>
            <button type="button" class="btn ghost" id="btnDeleteAllImport" ${files.length ? '' : 'disabled'}>Hapus semua file</button>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="center">No</th>
                <th>Nama file</th>
                <th class="num">Ukuran</th>
                <th>Diubah</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>Panduan hosting</h2>
            <p>1) Salin <code>config.example.php</code> → <code>config.php</code>, isi database hosting.<br>
            2) Folder sumber ada di <code>data/semua/</code> (harus writable). Jika upload gagal, jalankan <code>php fix_permissions.php</code> sekali.<br>
            3) Unggah file Excel lewat menu ini (komputer) atau tempel URL cloud publik.<br>
            4) Setelah impor, filter rekap otomatis memakai data baru.</p>
          </div>
        </div>
      </section>`;
  }

  function appUpdatePanelHtml(app) {
    if (!can('app_update')) return '';
    const a = app || {};
    const available = a.available === true;
    const method = a.method === 'zip' ? 'ZIP GitHub' : (a.method === 'git' ? 'git pull' : '—');
    let meta = '';
    if (!available) {
      meta = `<p class="muted">${esc(a.reason || 'Update tidak tersedia di server ini.')}</p>`;
    } else {
      const when = a.committed_at
        ? new Date(a.committed_at).toLocaleString('id-ID')
        : '—';
      const sync = a.behind > 0
        ? `<span class="badge">Ada update${a.remote_commit ? ` (${esc(a.remote_commit)})` : ''}</span>`
        : '<span class="badge rank">Sudah terbaru</span>';
      const dirty = a.dirty ? ' <span class="badge" style="background:#fef3c7;color:#92400e">Ada perubahan lokal</span>' : '';
      meta = `<p class="muted">Mode <strong>${esc(method)}</strong> · versi <code>${esc(a.commit || 'belum tercatat')}</code> · cabang <code>${esc(a.branch || '—')}</code> · ${esc(when)} ${sync}${dirty}</p>
        <p class="muted">${esc(a.message || '')}${a.remote ? ` · <code>${esc(a.remote)}</code>` : ''}</p>
        ${a.fetch_error ? `<p class="muted" style="color:#b91c1c">${esc(a.fetch_error)}</p>` : ''}`;
    }
    return `
      <section class="panel" id="panelUpdateApp">
        <div class="panel-head">
          <div>
            <h2 style="color:#0f5c45">Update aplikasi</h2>
            <p>Ambil versi terbaru dari GitHub. Di hosting tanpa git dipakai unduhan ZIP. Data sekolah, Excel, dan <code>config.php</code> tidak ikut berubah.</p>
            ${meta}
          </div>
          <div class="panel-actions">
            <button type="button" class="btn ghost" id="btnCekUpdateApp" ${available ? '' : 'disabled'}>Cek update</button>
            <button type="button" class="btn primary" id="btnUpdateApp" ${available ? '' : 'disabled'}>Update Aplikasi</button>
          </div>
        </div>
        <pre class="muted" id="updateAppLog" hidden style="white-space:pre-wrap;font-size:0.85rem;margin:0;max-height:12rem;overflow:auto"></pre>
      </section>`;
  }

  function fillAppUpdatePanel(app) {
    const panel = $('#panelUpdateApp');
    if (!panel || !can('app_update')) return;
    const wrap = document.createElement('div');
    wrap.innerHTML = appUpdatePanelHtml(app);
    const next = wrap.firstElementChild;
    if (next) panel.replaceWith(next);
  }

  function renderPengaturanSekolah(data) {
    const canManage = can('sekolah_manage');
    const list = data.sekolah || [];
    const aktif = data.aktif || list.find((s) => s.current) || list.find((s) => s.aktif) || list[0] || {};
    const usage = data.usage || { pernah: 0, sedang_pakai: 0, siap: 0, total_slots: 0, history: [] };
    const appPanel = appUpdatePanelHtml(data.app);
    const fmtUsageAt = (iso) => {
      if (!iso) return '—';
      const d = new Date(iso);
      if (Number.isNaN(d.getTime())) return '—';
      return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    // Admin: hanya edit sekolah aktif
    if (!canManage) {
      const edit = aktif;
      state.sekolahEditId = edit.id || '';
      view.innerHTML = `
        ${appPanel}
        <section class="panel">
          <div class="panel-head">
            <div>
              <h2 style="color:#0f5c45">Edit sekolah</h2>
              <p>Ubah nama, alamat, kepala sekolah, dan logo sekolah aktif.</p>
            </div>
          </div>
          <form id="formSekolah" class="sekolah-form" autocomplete="off">
            <input type="hidden" id="sekId" value="${esc(edit.id || '')}" />
            <label>
              <span>ID sekolah</span>
              <input type="text" value="${esc(edit.id || '—')}" readonly disabled />
            </label>
            <label>
              <span>Nama sekolah</span>
              <input type="text" id="sekNama" required maxlength="120" value="${esc(edit.nama || '')}" placeholder="Sekolah 1" />
            </label>
            <label class="grow">
              <span>Alamat sekolah</span>
              <input type="text" id="sekAlamat" maxlength="300" value="${esc(edit.alamat || '')}" placeholder="Jl. …, Kecamatan …, Kabupaten …" />
            </label>
            <label>
              <span>Nama kepala sekolah</span>
              <input type="text" id="sekKepala" maxlength="120" value="${esc(edit.kepala_nama || '')}" placeholder="Nama lengkap" />
            </label>
            <label>
              <span>NIP kepala sekolah</span>
              <input type="text" id="sekNip" maxlength="40" value="${esc(edit.kepala_nip || '')}" placeholder="1980xxxxxxxxxx" />
            </label>
            <label>
              <span>Tempat cetak</span>
              <input type="text" id="sekTempat" maxlength="80" value="${esc(edit.tempat_cetak || '')}" placeholder="Sleman" />
            </label>
            <label>
              <span>Tanggal cetak (kosongkan = hari ini saat cetak)</span>
              <input type="date" id="sekTanggal" value="${esc(edit.tanggal_cetak || '')}" />
            </label>
            <label class="grow">
              <span>Keterangan tambahan (opsional)</span>
              <input type="text" id="sekKet" maxlength="200" value="${esc(edit.keterangan || '')}" placeholder="Opsional" />
            </label>
            <div class="filter-actions" style="grid-column:1/-1">
              <button type="submit" class="btn primary">Simpan</button>
            </div>
          </form>
        </section>

        ${edit.id ? `
        <section class="panel">
          <div class="panel-head">
            <div>
              <h2>Logo sekolah</h2>
              <p>PNG / JPG / WEBP / GIF, maksimal 2 MB.</p>
            </div>
          </div>
          <div style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap">
            ${edit.logo_url
              ? `<img class="logo-preview" src="${esc(edit.logo_url)}" alt="Logo" />`
              : '<div class="muted">Belum ada logo</div>'}
            <form id="formLogoSekolah">
              <input type="hidden" name="id" value="${esc(edit.id)}" />
              <input type="file" id="sekLogoFile" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" required />
              <div class="filter-actions" style="margin-top:0.5rem">
                <button type="submit" class="btn primary">Unggah logo</button>
                ${edit.logo_url ? `<button type="button" class="btn ghost" id="btnClearLogo" data-id="${esc(edit.id)}">Hapus logo</button>` : ''}
              </div>
            </form>
          </div>
        </section>` : ''}`;
      return;
    }

    const editingId = state.sekolahEditId === '__new__'
      ? ''
      : (state.sekolahEditId || aktif.id || '');
    const edit = editingId
      ? (list.find((s) => s.id === editingId) || {
        id: '', nama: '', kepala_nama: '', kepala_nip: '', alamat: '', tempat_cetak: '', tanggal_cetak: '', keterangan: '', logo_url: '',
      })
      : {
        id: '', nama: '', kepala_nama: '', kepala_nip: '', alamat: '', tempat_cetak: '', tanggal_cetak: '', keterangan: '', logo_url: '',
      };

    const rows = list.map((s, i) => `<tr class="${s.current ? 'aktif-row' : ''}">
      <td class="center">${i + 1}</td>
      <td><code class="muted">${esc(s.id)}</code></td>
      <td>
        <strong>${esc(s.nama)}</strong>
        ${s.aktif ? ' <span class="badge">Aktif</span>' : ' <span class="badge rank">Nonaktif</span>'}
        ${s.utama ? ' <span class="badge">Utama</span>' : ''}
        ${s.dipakai ? ' <span class="badge">Sudah dipakai</span>' : ' <span class="badge rank">Belum dipakai</span>'}
        ${s.dipakai && !s.utama ? ' <span class="badge rank">Reset 7 hari</span>' : ''}
        ${s.current ? ' <span class="badge rank">Di sesi</span>' : ''}
        <div class="muted">${esc(s.kepala_nama || '—')} · NIP ${esc(s.kepala_nip || '—')}</div>
        ${s.alamat ? `<div class="muted">${esc(s.alamat)}</div>` : '<div class="muted">Alamat belum diisi</div>'}
      </td>
      <td>${esc(s.tempat_cetak || '—')}</td>
      <td>${esc(s.tanggal_cetak || 'Hari cetak')}</td>
      <td class="aksi">
        <button type="button" class="btn ghost btn-sm" data-sekolah-edit="${esc(s.id)}">Edit</button>
        ${s.current ? '' : `<button type="button" class="btn ghost btn-sm" data-sekolah-aktif="${esc(s.id)}">Pakai di sesi</button>`}
        ${list.length > 1 ? `<button type="button" class="btn ghost btn-sm" data-sekolah-hapus="${esc(s.id)}">Hapus</button>` : ''}
      </td>
    </tr>`).join('') || `<tr><td colspan="6" class="center muted">Belum ada sekolah.</td></tr>`;

    const usageRows = (usage.history || []).map((h, i) => `<tr>
      <td class="center">${i + 1}</td>
      <td><code class="muted">${esc(h.id || '')}</code></td>
      <td><strong>${esc(h.nama || h.id || '')}</strong></td>
      <td class="center">${esc(String(h.times || 1))}×</td>
      <td>${esc(fmtUsageAt(h.first_at))}</td>
      <td>${esc(fmtUsageAt(h.last_at))}</td>
    </tr>`).join('') || `<tr><td colspan="6" class="center muted">Belum ada sekolah yang tercatat memakai (selain Sekolah 1).</td></tr>`;

    view.innerHTML = `
      ${appPanel}
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2 style="color:#0f5c45">Statistik pemakaian</h2>
            <p>Nama sekolah yang pernah memakai tetap tersimpan (tidak ikut terhapus saat slot di-reset). Sekolah 1 / utama tidak dihitung. Hitungan “kali” naik jika nama yang sama memakai ulang slot yang sama.</p>
          </div>
        </div>
        <div class="stats" style="margin-bottom:0.85rem">
          <div class="stat"><strong>${esc(String(usage.pernah || 0))}</strong>pernah memakai</div>
          <div class="stat"><strong>${esc(String(usage.sedang_pakai || 0))}</strong>sedang dipakai</div>
          <div class="stat"><strong>${esc(String(usage.siap || 0))}</strong>siap dipakai</div>
          <div class="stat"><strong>${esc(String(usage.total_slots || 0))}</strong>slot non-utama</div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="center">No</th>
                <th>ID</th>
                <th>Nama terakhir</th>
                <th class="center">Kali</th>
                <th>Pertama</th>
                <th>Terakhir</th>
              </tr>
            </thead>
            <tbody>${usageRows}</tbody>
          </table>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <div>
            <h2 style="color:#0f5c45">Pengaturan sekolah</h2>
            <p>Semua sekolah berstatus aktif (100 slot: SCH01–SCH100). Data siswa/Excel <strong>terpisah per sekolah</strong>. Login menampilkan sekolah <strong>belum terpakai</strong> (kecuali Sekolah 1). Sekolah non-utama yang sudah dipakai otomatis <strong>reset setelah 7 hari</strong>. Sekolah 1 tidak di-reset. Ganti password default setelah login.</p>
          </div>
          <div class="panel-actions">
            <button type="button" class="btn ghost" id="btnSekolahBaru">+ Sekolah baru</button>
          </div>
        </div>
        <div class="table-wrap sekolah-list">
          <table>
            <thead>
              <tr>
                <th class="center">No</th>
                <th>ID</th>
                <th>Nama / Alamat</th>
                <th>Tempat cetak</th>
                <th>Tanggal cetak</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>${edit.id ? 'Edit sekolah' : 'Tambah sekolah'}</h2>
            <p>ID dibuat otomatis. Ubah nama dan alamat sesuai kebutuhan.</p>
          </div>
        </div>
        <form id="formSekolah" class="sekolah-form" autocomplete="off">
          <input type="hidden" id="sekId" value="${esc(edit.id || '')}" />
          <label>
            <span>ID sekolah</span>
            <input type="text" value="${esc(edit.id || '(otomatis saat simpan)')}" readonly disabled />
          </label>
          <label>
            <span>Nama sekolah</span>
            <input type="text" id="sekNama" required maxlength="120" value="${esc(edit.nama || '')}" placeholder="Sekolah 1" />
          </label>
          <label class="grow">
            <span>Alamat sekolah</span>
            <input type="text" id="sekAlamat" maxlength="300" value="${esc(edit.alamat || '')}" placeholder="Jl. …, Kecamatan …, Kabupaten …" />
          </label>
          <label>
            <span>Nama kepala sekolah</span>
            <input type="text" id="sekKepala" maxlength="120" value="${esc(edit.kepala_nama || '')}" placeholder="Nama lengkap" />
          </label>
          <label>
            <span>NIP kepala sekolah</span>
            <input type="text" id="sekNip" maxlength="40" value="${esc(edit.kepala_nip || '')}" placeholder="1980xxxxxxxxxx" />
          </label>
          <label>
            <span>Tempat cetak</span>
            <input type="text" id="sekTempat" maxlength="80" value="${esc(edit.tempat_cetak || '')}" placeholder="Sleman" />
          </label>
          <label>
            <span>Tanggal cetak (kosongkan = hari ini saat cetak)</span>
            <input type="date" id="sekTanggal" value="${esc(edit.tanggal_cetak || '')}" />
          </label>
          <label class="grow">
            <span>Keterangan tambahan (opsional)</span>
            <input type="text" id="sekKet" maxlength="200" value="${esc(edit.keterangan || '')}" placeholder="Opsional" />
          </label>
          <div class="filter-actions" style="grid-column:1/-1">
            <button type="submit" class="btn primary">Simpan</button>
            ${edit.id ? '<button type="button" class="btn ghost" id="btnSekolahBatal">Batal edit</button>' : ''}
          </div>
        </form>
      </section>

      ${edit.id ? `
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>Logo sekolah</h2>
            <p>PNG / JPG / WEBP / GIF, maksimal 2 MB. Tampil di header aplikasi dan dokumen cetak.</p>
          </div>
        </div>
        <div style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap">
          ${edit.logo_url
            ? `<img class="logo-preview" src="${esc(edit.logo_url)}" alt="Logo" />`
            : '<div class="muted">Belum ada logo</div>'}
          <form id="formLogoSekolah">
            <input type="hidden" name="id" value="${esc(edit.id)}" />
            <input type="file" id="sekLogoFile" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" required />
            <div class="filter-actions" style="margin-top:0.5rem">
              <button type="submit" class="btn primary">Unggah logo</button>
              ${edit.logo_url ? `<button type="button" class="btn ghost" id="btnClearLogo" data-id="${esc(edit.id)}">Hapus logo</button>` : ''}
            </div>
          </form>
        </div>
      </section>` : ''}`;
  }

  async function loadView() {
    showStatus('');
    syncFilterPanel();
    const f = currentFilters();
    const token = ++state.loadToken;
    state.renderToken++; // batalkan render bertahap sebelumnya

    view.classList.add('is-loading');
    if (!view.querySelector('.panel, .student-hero, .ijazah-angkatan, table')) {
      view.innerHTML = `<div class="empty">Memuat…</div>`;
    }

    try {
      if (state.mode === 'kktp') {
        const data = await api('kktp');
        if (token !== state.loadToken) return;
        renderKktp(data);
        return;
      }

      if (state.mode === 'kelola_kelas') {
        const data = await api('list_kelas');
        if (token !== state.loadToken) return;
        renderKelolaKelas(data);
        return;
      }

      if (state.mode === 'pengaturan_sekolah') {
        const data = await api('list_sekolah');
        if (token !== state.loadToken) return;
        applySekolahBranding(data.aktif, data.sekolah);
        renderPengaturanSekolah(data);
        return;
      }

      if (state.mode === 'kelola_user') {
        const data = await api('list_users');
        if (token !== state.loadToken) return;
        state.roles = data.roles || state.roles;
        renderKelolaUser(data);
        return;
      }

      if (state.mode === 'impor_data') {
        const data = await api('list_import');
        if (token !== state.loadToken) return;
        renderImporData(data);
        return;
      }

      if (isUjianMode()) {
        if (state.ujianDetail && state.ujianDetail.jenis === ujianJenisFromMode()) {
          renderUjianDetail(state.ujianDetail);
          return;
        }
        const jenis = ujianJenisFromMode();
        const data = await api('list_ujian', { jenis });
        if (token !== state.loadToken) return;
        renderUjianList(data, jenis);
        return;
      }

      state.ujianDetail = null;

      if (state.mode === 'nilai_ijazah') {
        const data = await api('nilai_ijazah', f);
        if (token !== state.loadToken) return;
        state.viewData = data;
        if (data.mode === 'detail') renderIjazahDetail(data);
        else renderIjazahDaftar(data);
        return;
      }

      if (state.mode === 'per_siswa' && !f.id) {
        view.innerHTML = `<div class="empty">Pilih <strong>ID siswa (NISN)</strong> di filter, atau pakai tombol <strong>Cari siswa</strong>.</div>`;
        return;
      }

      const data = await api(state.mode, f);
      if (token !== state.loadToken) return;
      state.viewData = data;

      if (state.mode === 'per_semester') renderPerSemester(data);
      else if (state.mode === 'semua_semester') renderSemuaSemester(data);
      else renderPerSiswa(data);
    } catch (err) {
      if (err?.name === 'AbortError') return;
      showStatus(err.message, true);
      view.innerHTML = `<div class="empty">Gagal memuat data.</div>`;
    } finally {
      if (token === state.loadToken) view.classList.remove('is-loading');
    }
  }

  function normalizeStudentKey(raw) {
    const digits = String(raw ?? '').replace(/\D+/g, '');
    if (!digits) return String(raw ?? '').trim();
    return digits.replace(/^0+/, '') || '0';
  }

  function resolveSiswaFilterId(rawId) {
    const want = String(rawId ?? '').trim();
    if (!want) return '';
    const all = state.filters?.students || [];
    const exact = all.find((s) =>
      String(s.id) === want || String(s.nisn || '') === want || String(s.nis || '') === want
    );
    if (exact) return String(exact.id);

    const key = normalizeStudentKey(want);
    const soft = all.find((s) =>
      normalizeStudentKey(s.id) === key
      || normalizeStudentKey(s.nisn) === key
      || normalizeStudentKey(s.nis) === key
    );
    return soft ? String(soft.id) : want;
  }

  function openSiswaById(rawId) {
    const id = resolveSiswaFilterId(rawId);
    if (!id) return;

    const sel = $('#fSiswa');
    // Pastikan opsi ada di dropdown (mis. filter kelas sempat menyembunyikan siswa)
    if (![...sel.options].some((o) => o.value === id)) {
      const all = state.filters?.students || [];
      const found = all.find((s) => String(s.id) === id);
      if (found) {
        $('#fKelas').value = '';
        fillSiswaSelect();
      }
    }
    if ([...sel.options].some((o) => o.value === id)) {
      sel.value = id;
    }

    if (state.mode !== 'per_siswa') {
      setMode('per_siswa');
    } else {
      loadView();
    }
  }

  function closeSiswaSearch() {
    const modal = $('#siswaSearchModal');
    if (!modal) return;
    modal.hidden = true;
    const inp = $('#siswaSearchInput');
    if (inp) inp.value = '';
    const list = $('#siswaSearchResults');
    if (list) list.innerHTML = '';
    const meta = $('#siswaSearchMeta');
    if (meta) meta.textContent = 'Ketikan untuk mencari.';
  }

  function renderSiswaSearchResults(q) {
    const list = $('#siswaSearchResults');
    const meta = $('#siswaSearchMeta');
    if (!list || !meta) return;

    const query = String(q || '').trim().toLowerCase();
    const all = state.filters?.students || [];
    if (!query) {
      list.innerHTML = '';
      meta.textContent = all.length
        ? `${all.length} siswa tersedia. Ketik nama / NISN / NIS.`
        : 'Belum ada data siswa. Sinkronkan Excel dulu.';
      return;
    }

    const digits = query.replace(/\D+/g, '');
    const hits = all.filter((s) => {
      const nama = String(s.nama || '').toLowerCase();
      const nisn = String(s.nisn || s.id || '').toLowerCase();
      const nis = String(s.nis || '').toLowerCase();
      if (nama.includes(query) || nisn.includes(query) || nis.includes(query)) return true;
      if (digits !== '') {
        const n1 = normalizeStudentKey(s.nisn || s.id || '');
        const n2 = normalizeStudentKey(s.nis || '');
        if (n1.includes(digits) || n2.includes(digits) || normalizeStudentKey(digits) === n1) return true;
      }
      return false;
    }).slice(0, 40);

    meta.textContent = hits.length
      ? `${hits.length}${hits.length === 40 ? '+' : ''} hasil untuk “${q.trim()}”`
      : `Tidak ada siswa cocok dengan “${q.trim()}”`;

    if (!hits.length) {
      list.innerHTML = `<li class="siswa-search-empty">Tidak ditemukan.</li>`;
      return;
    }

    list.innerHTML = hits.map((s) => {
      const kelas = (s.kelas_list || []).join(' / ') || '—';
      return `<li>
        <button type="button" role="option" data-pick-siswa="${esc(s.id)}">
          <span class="nama">${esc(s.nama || '—')}</span>
          <span class="meta">NISN ${esc(s.nisn || s.id || '—')} · NIS ${esc(s.nis || '—')} · ${esc(kelas)}</span>
        </button>
      </li>`;
    }).join('');
  }

  function openSiswaSearch() {
    const modal = $('#siswaSearchModal');
    if (!modal) return;
    modal.hidden = false;
    renderSiswaSearchResults('');
    const inp = $('#siswaSearchInput');
    if (inp) {
      inp.focus();
      inp.select();
    }
  }

  function setMode(mode) {
    if (mode === state.mode) {
      return;
    }
    state.ujianDetail = null;
    const prevMode = state.mode;
    state.mode = mode;
    document.querySelectorAll('.tab[data-mode]').forEach((tab) => {
      const active = tab.dataset.mode === mode;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    if (mode === 'nilai_ijazah' || prevMode === 'nilai_ijazah') {
      const cur = $('#fSiswa')?.value || '';
      fillSiswaSelect();
      if (cur && $('#fSiswa') && ![...($('#fSiswa').options || [])].some((o) => o.value === cur)) {
        $('#fSiswa').value = '';
      } else if ($('#fSiswa') && cur) {
        $('#fSiswa').value = cur;
      }
    }
    loadView();
  }

  document.querySelectorAll('.tab[data-mode]').forEach((tab) => {
    // Cegah fokus saat klik — browser tidak scroll/geser halaman ke kanan mencari tombol fokus
    tab.addEventListener('mousedown', (e) => {
      if (e.button === 0) e.preventDefault();
    });
    tab.addEventListener('click', () => setMode(tab.dataset.mode));
  });

  let loadTimer = null;
  function loadViewSoon() {
    clearTimeout(loadTimer);
    loadTimer = setTimeout(loadView, 80);
  }

  function filterModesAutoLoad() {
    return state.mode === 'per_semester'
      || state.mode === 'semua_semester'
      || state.mode === 'per_siswa'
      || state.mode === 'nilai_ijazah';
  }

  function onFilterDropdownChange(id) {
    if (!filterModesAutoLoad()) return;
    if (id === 'fKelas') fillSiswaSelect();
    if (id === 'fSiswa' && $(`#${id}`).value && state.mode !== 'nilai_ijazah') {
      if (state.mode !== 'per_siswa') {
        setMode('per_siswa');
      } else {
        loadViewSoon();
      }
      return;
    }
    loadViewSoon();
  }

  $('#btnReset').addEventListener('click', () => {
    ['fTahun', 'fSemester', 'fKelas', 'fSiswa'].forEach((id) => {
      $(`#${id}`).value = '';
    });
    fillSiswaSelect();
    clearApiCache();
    loadView();
  });

  const openSearchHandlers = () => openSiswaSearch();
  $('#btnCariSiswa')?.addEventListener('click', openSearchHandlers);

  $('#btnPetunjuk')?.addEventListener('click', () => {
    const modal = $('#petunjukModal');
    if (modal) modal.hidden = false;
  });

  const petunjukModal = $('#petunjukModal');
  if (petunjukModal) {
    petunjukModal.addEventListener('click', (e) => {
      if (e.target.closest('[data-petunjuk-close]')) {
        petunjukModal.hidden = true;
      }
    });
  }

  const siswaModal = $('#siswaSearchModal');
  if (siswaModal) {
    siswaModal.addEventListener('click', (e) => {
      if (e.target.closest('[data-siswa-search-close]')) {
        closeSiswaSearch();
        return;
      }
      const pick = e.target.closest('[data-pick-siswa]');
      if (pick) {
        const id = pick.dataset.pickSiswa;
        closeSiswaSearch();
        openSiswaById(id);
      }
    });
  }

  let siswaSearchTimer = null;
  $('#siswaSearchInput')?.addEventListener('input', (e) => {
    clearTimeout(siswaSearchTimer);
    const q = e.target.value;
    siswaSearchTimer = setTimeout(() => renderSiswaSearchResults(q), 120);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const petunjuk = $('#petunjukModal');
    if (petunjuk && !petunjuk.hidden) {
      e.preventDefault();
      petunjuk.hidden = true;
      return;
    }
    const modal = $('#siswaSearchModal');
    if (!modal || modal.hidden) return;
    e.preventDefault();
    closeSiswaSearch();
  });

  $('#btnRefresh').addEventListener('click', async () => {
    showStatus('Menyinkronkan dari Excel…');
    try {
      clearApiCache();
      const r = await apiPost('refresh');
      await loadFilters();
      showStatus(`Sinkron sukses: ${r.files || 0} file, ${r.students} siswa, ${r.semesters} semester, ${r.records} baris nilai.`);
      await loadView();
    } catch (err) {
      showStatus(err.message, true);
    }
  });

  view.addEventListener('input', (e) => {
    if (e.target.id === 'bPraktek' || e.target.id === 'bTeori') {
      syncBobotRaporAuto();
    }
  });

  view.addEventListener('click', (e) => {
    const sortBtnEl = e.target.closest('.sort-btn');
    if (sortBtnEl) {
      e.preventDefault();
      applyTableSort(sortBtnEl.dataset.sortMode || state.mode, sortBtnEl.dataset.sortKey || 'nama');
      return;
    }

    const btnKktpReset = e.target.closest('#btnKktpResetDefault');
    if (btnKktpReset) {
      document.querySelectorAll('#formKktp input[data-tingkat]').forEach((inp) => {
        inp.value = '75';
      });
      return;
    }

    const openBtn = e.target.closest('[data-open-siswa]');
    if (openBtn) {
      openSiswaById(openBtn.dataset.openSiswa);
      return;
    }

    const hapusBtn = e.target.closest('[data-hapus-kelas]');
    if (hapusBtn) {
      const id = hapusBtn.dataset.hapusKelas;
      if (!id || !confirm('Hapus kelas ini dari master?')) return;
      (async () => {
        try {
          const res = await apiPost('delete_kelas', { id });
          showStatus(res.message || 'Kelas dihapus.');
          await loadFilters();
          renderKelolaKelas({ kelas: res.list || [] });
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const backBtn = e.target.closest('[data-ujian-back]');
    if (backBtn) {
      state.ujianDetail = null;
      loadView();
      return;
    }

    const openUjian = e.target.closest('[data-ujian-open]');
    if (openUjian) {
      const id = openUjian.dataset.ujianOpen;
      (async () => {
        try {
          const res = await api('get_ujian', { id });
          state.ujianDetail = res.ujian;
          renderUjianDetail(res.ujian);
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const hapusUjian = e.target.closest('[data-ujian-hapus]');
    if (hapusUjian) {
      const id = hapusUjian.dataset.ujianHapus;
      if (!id || !confirm('Hapus sesi ujian ini?')) return;
      (async () => {
        try {
          const res = await apiPost('delete_ujian', { id });
          showStatus(res.message || 'Ujian dihapus.');
          state.ujianDetail = null;
          await loadView();
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const saveUjian = e.target.closest('[data-ujian-save]');
    if (saveUjian) {
      const id = saveUjian.dataset.ujianSave;
      (async () => {
        try {
          const siswa = collectNilaiFromTable();
          const res = await apiPost('save_nilai_ujian', { id, siswa });
          showStatus(res.message || 'Nilai disimpan.');
          state.ujianDetail = res.ujian;
          renderUjianDetail(res.ujian);
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const ijazahSiswa = e.target.closest('[data-ijazah-siswa]');
    if (ijazahSiswa) {
      $('#fSiswa').value = ijazahSiswa.dataset.ijazahSiswa;
      fillSiswaSelect();
      $('#fSiswa').value = ijazahSiswa.dataset.ijazahSiswa;
      loadView();
      return;
    }

    const ijazahBack = e.target.closest('[data-ijazah-back]');
    if (ijazahBack) {
      $('#fSiswa').value = '';
      fillSiswaSelect();
      loadView();
      return;
    }

    const importDel = e.target.closest('[data-import-del]');
    if (importDel) {
      const name = importDel.dataset.importDel;
      if (!name || !confirm(`Hapus file "${name}" dari sumber data?`)) return;
      (async () => {
        try {
          const res = await apiPost('delete_import', { name });
          showStatus(res.message || 'File dihapus.');
          await loadFilters();
          renderImporData({ health: (await api('list_import')).health, files: res.files || [] });
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    if (e.target.closest('#btnSyncImport')) {
      (async () => {
        try {
          const r = await apiPost('refresh');
          await loadFilters();
          showStatus(`Sinkron sukses: ${r.files || 0} file, ${r.students} siswa, ${r.records} baris.`);
          const data = await api('list_import');
          renderImporData(data);
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    if (e.target.closest('#btnDeleteAllImport')) {
      if (!confirm('Hapus SEMUA file Excel di folder sumber? Tindakan ini tidak bisa dibatalkan.')) return;
      if (!confirm('Yakin hapus semua file? Cache rekap akan dikosongkan.')) return;
      (async () => {
        try {
          showStatus('Menghapus semua file…');
          const res = await apiPost('delete_import_all', {});
          await loadFilters();
          showStatus(res.message || 'Semua file dihapus.');
          const data = await api('list_import');
          renderImporData(data);
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    if (e.target.closest('#btnCekUpdateApp')) {
      (async () => {
        try {
          showStatus('Memeriksa update dari remote…');
          const res = await api('app_version');
          fillAppUpdatePanel(res.app);
          const a = res.app || {};
          if (!a.available) {
            showStatus(a.reason || 'Update tidak tersedia.', true);
          } else if (a.behind > 0) {
            showStatus(`Tersedia ${a.behind} commit baru dari remote.`);
          } else {
            showStatus('Sudah versi terbaru.');
          }
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    if (e.target.closest('#btnUpdateApp')) {
      if (!confirm('Update aplikasi dari remote git sekarang?\n\nData sekolah & config.php aman (tidak diubah). Hard-refresh browser setelah selesai.')) return;
      (async () => {
        const btn = $('#btnUpdateApp');
        const logEl = $('#updateAppLog');
        try {
          if (btn) btn.disabled = true;
          showStatus('Mengupdate aplikasi…');
          const res = await apiPost('update_app', {});
          fillAppUpdatePanel(res.app);
          if (logEl) {
            logEl.hidden = !res.log;
            logEl.textContent = res.log || '';
          }
          showStatus(res.message || 'Update selesai.');
          if (res.updated) {
            setTimeout(() => {
              if (confirm('Update berhasil. Muat ulang halaman sekarang?')) {
                location.reload();
              }
            }, 400);
          }
        } catch (err) {
          showStatus(err.message, true);
        } finally {
          if (btn) btn.disabled = false;
        }
      })();
      return;
    }

    if (e.target.closest('#btnSekolahBaru')) {
      state.sekolahEditId = '__new__';
      (async () => {
        const data = await api('list_sekolah');
        renderPengaturanSekolah(data);
      })();
      return;
    }

    const sekEdit = e.target.closest('[data-sekolah-edit]');
    if (sekEdit) {
      state.sekolahEditId = sekEdit.dataset.sekolahEdit;
      (async () => {
        const data = await api('list_sekolah');
        renderPengaturanSekolah(data);
      })();
      return;
    }

    const sekAktif = e.target.closest('[data-sekolah-aktif]');
    if (sekAktif) {
      (async () => {
        try {
          const res = await apiPost('set_sekolah_aktif', { id: sekAktif.dataset.sekolahAktif });
          state.sekolahEditId = res.aktif?.id || '';
          applySekolahBranding(res.aktif, res.sekolah);
          showStatus(res.message || 'Sekolah aktif diubah. Data siswa mengikuti sekolah ini.');
          renderPengaturanSekolah(res);
          await loadFilters();
          loadViewSoon();
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const sekHapus = e.target.closest('[data-sekolah-hapus]');
    if (sekHapus) {
      if (!confirm('Hapus sekolah ini?')) return;
      (async () => {
        try {
          const res = await apiPost('delete_sekolah', { id: sekHapus.dataset.sekolahHapus });
          state.sekolahEditId = res.aktif?.id || '';
          applySekolahBranding(res.aktif, res.sekolah);
          showStatus(res.message || 'Sekolah dihapus.');
          renderPengaturanSekolah(res);
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    if (e.target.closest('#btnSekolahBatal')) {
      state.sekolahEditId = '';
      (async () => {
        const data = await api('list_sekolah');
        state.sekolahEditId = data.aktif?.id || '';
        renderPengaturanSekolah(data);
      })();
      return;
    }

    if (e.target.closest('#btnClearLogo')) {
      const id = e.target.closest('#btnClearLogo').dataset.id;
      if (!id || !confirm('Hapus logo sekolah ini?')) return;
      (async () => {
        try {
          const res = await apiPost('clear_logo_sekolah', { id });
          state.sekolahEditId = id;
          applySekolahBranding(res.aktif, res.sekolah);
          showStatus(res.message || 'Logo dihapus.');
          renderPengaturanSekolah(res);
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const userEdit = e.target.closest('[data-user-edit]');
    if (userEdit) {
      state.userEditId = userEdit.dataset.userEdit;
      (async () => {
        const data = await api('list_users');
        renderKelolaUser(data);
      })();
      return;
    }

    const userDel = e.target.closest('[data-user-del]');
    if (userDel) {
      const id = userDel.dataset.userDel;
      if (!id || !confirm('Hapus pengguna ini?')) return;
      (async () => {
        try {
          const res = await apiPost('delete_user', { id });
          state.userEditId = '';
          showStatus(res.message || 'Pengguna dihapus.');
          renderKelolaUser(res);
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    if (e.target.closest('#btnUserBatal')) {
      state.userEditId = '';
      (async () => {
        const data = await api('list_users');
        renderKelolaUser(data);
      })();
      return;
    }

  });

  view.addEventListener('submit', (e) => {
    const formKelas = e.target.closest('#formKelas');
    if (formKelas) {
      e.preventDefault();
      const payload = {
        nama: $('#kNama').value.trim(),
        tingkat: $('#kTingkat').value,
        tahun_ajaran: $('#kTahun').value.trim(),
        keterangan: $('#kKet').value.trim(),
      };
      (async () => {
        try {
          const res = await apiPost('add_kelas', payload);
          showStatus(res.message || 'Kelas ditambahkan.');
          formKelas.reset();
          await loadFilters();
          renderKelolaKelas({ kelas: res.list || [] });
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const formImportUjian = e.target.closest('#formImportUjian');
    if (formImportUjian) {
      e.preventDefault();
      const input = $('#ujianFile');
      if (!input || !input.files || !input.files.length) {
        showStatus('Pilih file Excel template ujian.', true);
        return;
      }
      const jenis = formImportUjian.dataset.jenis || ujianJenisFromMode();
      const fd = new FormData();
      fd.append('action', 'import_ujian_excel');
      fd.append('jenis', jenis);
      fd.append('kelas', $('#uKelas')?.value || '');
      fd.append('tahun_ajaran', $('#uTahun')?.value || '');
      fd.append('semester', $('#uSemester')?.value || '');
      fd.append('tanggal', $('#uTanggal')?.value || '');
      fd.append('keterangan', $('#uKet')?.value || '');
      fd.append('file', input.files[0]);
      (async () => {
        try {
          showStatus('Mengimpor template ujian…');
          const res = await apiUpload(fd);
          showStatus(res.message || 'Impor ujian selesai.');
          input.value = '';
          state.ujianDetail = null;
          renderUjianList({ ujian: res.ujian || [], templates: res.templates || {} }, jenis);
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const formBobot = e.target.closest('#formBobotIjazah');
    if (formBobot) {
      e.preventDefault();
      syncBobotRaporAuto();
      const praktek = Number($('#bPraktek').value);
      const teori = Number($('#bTeori').value);
      if (praktek + teori > 100) {
        showStatus('Total praktek + teori tidak boleh lebih dari 100%.', true);
        return;
      }
      const payload = {
        praktek,
        teori,
      };
      (async () => {
        try {
          const res = await apiPost('save_ijazah_bobot', payload);
          showStatus(res.message || 'Bobot disimpan.');
          await loadView();
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const formKktp = e.target.closest('#formKktp');
    if (formKktp) {
      e.preventDefault();
      const nilai = {};
      formKktp.querySelectorAll('input[data-tingkat]').forEach((inp) => {
        nilai[inp.dataset.tingkat] = Number(inp.value);
      });
      (async () => {
        try {
          const res = await apiPost('save_kktp', { nilai });
          showStatus(res.message || 'KKTP disimpan.');
          if (state.filters) state.filters.kktp = res.kktp;
          renderKktp(res.kktp || { tingkat: [], jenjang: [] });
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const formSekolah = e.target.closest('#formSekolah');
    if (formSekolah) {
      e.preventDefault();
      const payload = {
        id: $('#sekId').value.trim(),
        nama: $('#sekNama').value.trim(),
        kepala_nama: $('#sekKepala').value.trim(),
        kepala_nip: $('#sekNip').value.trim(),
        alamat: $('#sekAlamat').value.trim(),
        tempat_cetak: $('#sekTempat').value.trim(),
        tanggal_cetak: $('#sekTanggal').value.trim(),
        keterangan: $('#sekKet').value.trim(),
      };
      (async () => {
        try {
          const res = await apiPost('save_sekolah', payload);
          state.sekolahEditId = res.item?.id || res.aktif?.id || '';
          applySekolahBranding(res.aktif, res.sekolah);
          showStatus(res.message || 'Pengaturan sekolah disimpan.');
          renderPengaturanSekolah(res);
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const formLogo = e.target.closest('#formLogoSekolah');
    if (formLogo) {
      e.preventDefault();
      const input = $('#sekLogoFile');
      if (!input?.files?.length) {
        showStatus('Pilih file logo.', true);
        return;
      }
      const fd = new FormData(formLogo);
      fd.append('action', 'upload_logo_sekolah');
      (async () => {
        try {
          showStatus('Mengunggah logo…');
          const res = await apiUpload(fd);
          state.sekolahEditId = res.item?.id || $('#sekId')?.value || '';
          applySekolahBranding(res.aktif, res.sekolah);
          showStatus(res.message || 'Logo diunggah.');
          renderPengaturanSekolah(res);
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const formUser = e.target.closest('#formUser');
    if (formUser) {
      e.preventDefault();
      const payload = {
        id: $('#userId')?.value || '',
        username: $('#userUsername')?.value.trim() || '',
        nama: $('#userNama')?.value.trim() || '',
        role: $('#userRole')?.value || 'user',
        password: $('#userPassword')?.value || '',
        aktif: $('#userAktif')?.value === '1',
      };
      (async () => {
        try {
          const res = await apiPost('save_user', payload);
          state.userEditId = '';
          showStatus(res.message || 'Pengguna disimpan.');
          renderKelolaUser(res);
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const formChangePassword = e.target.closest('#formChangePassword');
    if (formChangePassword) {
      e.preventDefault();
      const payload = {
        old_password: $('#oldPassword')?.value || '',
        new_password: $('#newPassword')?.value || '',
      };
      (async () => {
        try {
          const res = await apiPost('change_password', payload);
          showStatus(res.message || 'Password diubah.');
          formChangePassword.reset();
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const formUpload = e.target.closest('#formUploadLocal');
    if (formUpload) {
      e.preventDefault();
      const input = $('#localFiles');
      if (!input || !input.files || !input.files.length) {
        showStatus('Pilih file .xlsx terlebih dahulu.', true);
        return;
      }
      const allFiles = [...input.files];
      // PHP max_file_uploads default 20 — unggah bertahap
      const batchSize = Math.max(1, Math.min(15, Number(state.filters?.max_file_uploads) || 15));
      (async () => {
        try {
          const saved = [];
          const errors = [];
          const total = allFiles.length;
          for (let i = 0; i < total; i += batchSize) {
            const chunk = allFiles.slice(i, i + batchSize);
            const batchNo = Math.floor(i / batchSize) + 1;
            const batchTotal = Math.ceil(total / batchSize);
            showStatus(`Mengunggah batch ${batchNo}/${batchTotal} (${Math.min(i + batchSize, total)}/${total} file)…`);
            const fd = new FormData();
            fd.append('action', 'upload_excel');
            // skip sync sampai batch terakhir
            if (i + batchSize < total) fd.append('skip_refresh', '1');
            chunk.forEach((file) => fd.append('files[]', file));
            const res = await apiUpload(fd);
            (res.saved || []).forEach((s) => saved.push(s));
            (res.errors || []).forEach((err) => errors.push(err));
          }
          // Pastikan cache tersinkron setelah semua batch
          if (total > batchSize) {
            showStatus('Menyinkronkan cache…');
            await api('filters', { refresh: 1 });
          }
          showStatus(
            `${saved.length} file berhasil diunggah.`
            + (errors.length ? ` · Peringatan: ${errors.join('; ')}` : '')
          );
          input.value = '';
          await loadFilters();
          const data = await api('list_import');
          renderImporData(data);
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
      return;
    }

    const formCloud = e.target.closest('#formImportCloud');
    if (formCloud) {
      e.preventDefault();
      const payload = {
        url: $('#cloudUrl').value.trim(),
        filename: $('#cloudName').value.trim(),
      };
      (async () => {
        try {
          showStatus('Mengimpor dari cloud…');
          const res = await apiPost('import_cloud', payload);
          showStatus(res.message || 'Impor cloud sukses.');
          formCloud.reset();
          await loadFilters();
          const data = await api('list_import');
          renderImporData(data);
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
    }
  });

  // Dropdown filter: langsung muat data tanpa tombol Tampilkan
  const filterPanel = $('#filterPanel');
  if (filterPanel) {
    filterPanel.addEventListener('change', (e) => {
      const id = e.target?.id;
      if (!id || !['fTahun', 'fSemester', 'fKelas', 'fSiswa'].includes(id)) return;
      onFilterDropdownChange(id);
    });
  }

  const selSekolah = $('#fSekolahAktif');
  if (selSekolah) {
    selSekolah.addEventListener('change', () => {
      const id = selSekolah.value;
      if (!id) return;
      (async () => {
        try {
          const res = await apiPost('set_sekolah_aktif', { id });
          applySekolahBranding(res.aktif, res.sekolah);
          showStatus(res.message || 'Sekolah aktif diubah. Data siswa mengikuti sekolah ini.');
          if (state.mode === 'pengaturan_sekolah') {
            state.sekolahEditId = res.aktif?.id || '';
            renderPengaturanSekolah(res);
          }
          await loadFilters();
          loadViewSoon();
        } catch (err) {
          showStatus(err.message, true);
        }
      })();
    });
  }

  (async () => {
    try {
      const me = await api('me');
      if (!me.user) {
        redirectLogin();
        return;
      }
      state.user = me.user;
      state.caps = me.capabilities || {};
      state.roles = me.roles || {};
      setCsrf(me.csrf || document.querySelector('meta[name="csrf-token"]')?.content || '');
      applyAuthUI();
      await loadFilters();
      await loadView();
    } catch (err) {
      showStatus(err.message, true);
      view.innerHTML = `<div class="empty">Tidak dapat memuat sumber Excel.</div>`;
    }
  })();
})();
