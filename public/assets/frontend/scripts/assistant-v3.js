/* Terazibul Asistanı V3 — sohbet paneli */
(function () {
  var root = document.querySelector('[data-tba]');
  if (!root) return;

  var endpoint = root.getAttribute('data-endpoint') || '/asistan/mesaj';
  var panel = root.querySelector('.tba');
  var log = root.querySelector('.tba-log');
  var form = root.querySelector('.tba-form');
  var input = root.querySelector('.tba-input');
  var sendBtn = root.querySelector('.tba-send');
  var dockBtn = root.querySelector('.tbl-btn');
  var label = root.querySelector('.tbl-label');
  var phoneHref = root.getAttribute('data-phone') || '';
  var waHref = root.getAttribute('data-wa') || '';

  var STORE = 'tba-thread-v3';
  var OPEN_KEY = 'tb-assistant-opened';
  var HELLO_KEY = 'tb-assistant-hello-shown';
  var OPEN_NOW = 'tba-open';
  var TTL = 24 * 3600 * 1000;

  var thread = load();   // {cid, state, history:[{role,text}], items:[{kind,...}], at}
  var busy = false;
  var mobile = window.matchMedia('(max-width: 767px)');

  // ------------------------------------------------------------ yardımcılar
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function icon(name) {
    var p = {
      send: '<path d="M5 12l14 0" /><path d="M13 18l6 -6" /><path d="M13 6l6 6" />',
      arrow: '<path d="M5 12l14 0" /><path d="M13 18l6 -6" /><path d="M13 6l6 6" />',
      reset: '<path d="M19.95 11a8 8 0 1 0 -.5 4m.5 5v-5h-5" />',
      close: '<path d="M18 6l-12 12" /><path d="M6 6l12 12" />',
      scale: '<path d="M7 20l10 0" /><path d="M6 6l6 -1l6 1" /><path d="M12 3l0 17" /><path d="M9 12l-3 -6l-3 6a3 3 0 0 0 6 0" /><path d="M21 12l-3 -6l-3 6a3 3 0 0 0 6 0" />',
      tag: '<path d="M6.5 7.5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M3 6v5.172a2 2 0 0 0 .586 1.414l7.71 7.71a2.41 2.41 0 0 0 3.408 0l5.592 -5.592a2.41 2.41 0 0 0 0 -3.408l-7.71 -7.71a2 2 0 0 0 -1.414 -.586h-5.172a3 3 0 0 0 -3 3" />',
      wrench: '<path d="M7 10h3v-3l-3.5 -3.5a6 6 0 0 1 8 8l6 6a2 2 0 0 1 -3 3l-6 -6a6 6 0 0 1 -8 -8l3.5 3.5" />',
      alert: '<path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0" /><path d="M12 16h.01" />',
      badge: '<path d="M6 9a6 6 0 1 0 12 0a6 6 0 1 0 -12 0" /><path d="M12 15l3.4 5.89l1.598 -3.233l3.598 .232l-3.4 -5.889" /><path d="M6.802 12l-3.4 5.89l3.598 -.233l1.598 3.232l3.4 -5.889" />',
      recycle: '<path d="M12 17l-2 2l2 2" /><path d="M10 19h9a2 2 0 0 0 1.75 -2.75l-.55 -1" /><path d="M8.536 11l-.732 -2.732l-2.732 .732" /><path d="M7.804 8.268l-4.5 7.794a2 2 0 0 0 1.506 2.89l1.141 .024" /><path d="M15.464 11l2.732 .732l.732 -2.732" /><path d="M18.196 11.732l-4.5 -7.794a2 2 0 0 0 -3.256 -.14l-.591 .976" />',
      phone: '<path d="M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5l1.5 -2.5l5 2v4a2 2 0 0 1 -2 2a16 16 0 0 1 -15 -15a2 2 0 0 1 2 -2" />',
      form: '<path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2" /><path d="M9 9l1 0" /><path d="M9 13l6 0" /><path d="M9 17l6 0" />',
      link: '<path d="M9 15l6 -6" /><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" /><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />'
    };
    if (name === 'wa') {
      return '<svg class="tba-wa-ico" aria-hidden="true" viewBox="0 0 38 38"><path fill="currentColor" d="M1.01913 36.7046C0.933663 37.0624 1.03997 37.4407 1.31304 37.6998C1.51523 37.8951 1.78205 38 2.05926 38C2.15098 38 2.24061 37.9897 2.32607 37.9692L11.0082 35.7423C13.522 36.8958 16.2048 37.4798 19.0019 37.4798C29.4764 37.4798 38 29.0742 38 18.7399C38 8.40774 29.4727 0 18.9981 0C8.5236 0 0 8.40774 0 18.7399C0 22.3093 1.01303 25.7617 2.94118 28.7573L1.01913 36.7046ZM10.5555 10.3966L11.9313 9.10738C12.4545 8.61802 13.282 8.63859 13.7781 9.15467L16.8777 12.3767C17.3738 12.8927 17.353 13.709 16.8298 14.1984L15.4749 15.4732C15.4749 15.4732 13.6822 16.5362 17.4384 20.1159L17.5927 20.2784C21.0237 24.1624 22.1931 22.4517 22.1931 22.4517L23.5481 21.1769C24.0713 20.6875 24.8988 20.7081 25.3949 21.2242L28.4424 24.3886C28.9385 24.9047 28.9176 25.7209 28.3944 26.2103L27.0187 27.4995C25.9681 28.4844 24.4944 28.9368 23.0749 28.6468C21.1488 28.2582 18.266 26.9094 14.397 23.1136C14.4721 23.1979 14.5471 23.276 14.6222 23.3665L14.1532 22.8813C14.2324 22.9656 14.3137 23.0396 14.3929 23.1177C10.7492 19.1165 9.53187 16.207 9.23795 14.2905C9.0295 12.8656 9.50495 11.3815 10.5555 10.3966Z"/></svg>';
    }
    return '<svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (p[name] || p.arrow) + '</svg>';
  }
  // Buton yazıları: her kelimenin baş harfi büyük (birimler ve sayılar hariç)
  var UNIT = /^(g|kg|mg|gr|ct|nm|cm|mm|ml|l)(['’]\S*)?$/i;
  function tc(s) {
    return String(s || '').split(/(\s+)/).map(function (w) {
      if (!w.trim()) return w;
      var m = w.match(/^([^0-9A-Za-zÇĞİÖŞÜçğıöşü]*)(.*)$/);
      var lead = m ? m[1] : '';
      var rest = m ? m[2] : w;
      if (!rest || /^[0-9]/.test(rest) || UNIT.test(rest.replace(/[),.]+$/, ''))) return w;
      return lead + rest.charAt(0).toLocaleUpperCase('tr-TR') + rest.slice(1);
    }).join('');
  }
  function safeUrl(u) { return typeof u === 'string' && /^(https?:\/\/|\/|tel:)/.test(u) ? u : ''; }
  function sameSite(u) { try { return new URL(u, location.origin).origin === location.origin; } catch (e) { return false; } }
  function newCid() {
    var b = new Uint8Array(12), s = '';
    if (window.crypto && crypto.getRandomValues) crypto.getRandomValues(b); else for (var i = 0; i < 12; i++) b[i] = Math.random() * 256 | 0;
    for (var j = 0; j < b.length; j++) s += ('0' + b[j].toString(16)).slice(-2);
    return s;
  }
  function load() {
    try {
      var t = JSON.parse(localStorage.getItem(STORE) || 'null');
      if (t && t.at && Date.now() - t.at < TTL && Array.isArray(t.items)) return t;
    } catch (e) {}
    return { cid: newCid(), state: {}, history: [], items: [], at: Date.now() };
  }
  function save() {
    thread.at = Date.now();
    if (thread.items.length > 60) thread.items = thread.items.slice(-60);
    try { localStorage.setItem(STORE, JSON.stringify(thread)); } catch (e) {}
  }
  function scrollEnd(node) {
    window.requestAnimationFrame(function () {
      var bottom = log.scrollHeight - log.clientHeight;
      // Uzun bot cevabında mesajın başı görünsün; kısa olanlarda en alta in
      log.scrollTop = node ? Math.min(node.offsetTop - 12, bottom) : bottom;
    });
  }

  // ------------------------------------------------------------ çizim
  function renderWelcome() {
    var w = el('div', 'tba-welcome');
    w.appendChild(el('h3', 'tba-welcome__title', 'Merhaba, nasıl yardımcı olabilirim?'));
    w.appendChild(el('p', 'tba-welcome__text', 'Size uygun teraziyi bulabilir, fiyat bilgisi verebilir ya da arıza ve hata kodlarında yardımcı olabilirim. Aşağıdan seçin veya sorunuzu yazın.'));
    var g = el('div', 'tba-starters');
    var starters = [
      { icon: 'scale', title: 'Terazi seçmeme yardım et', sub: 'Birkaç soruyla doğru modeli bulalım', select: { facet: 'start', value: '1' }, text: 'Terazi seçmeme yardım eder misiniz?' },
      { icon: 'wrench', title: 'Terazim için servis istiyorum', sub: 'Tamir, bakım, kalibrasyon', send: 'Terazim arızalı, servis almak istiyorum' }
    ];
    starters.forEach(function (s) {
      var b = el('button', 'tba-starter');
      b.type = 'button';
      var ic = el('span', 'tba-starter__icon'); ic.innerHTML = icon(s.icon); b.appendChild(ic);
      b.appendChild(el('span', 'tba-starter__title', tc(s.title)));
      b.appendChild(el('span', 'tba-starter__sub', s.sub));
      b.addEventListener('click', function () {
        if (s.select) ask(s.text, s.select); else ask(s.send, null);
      });
      g.appendChild(b);
    });
    w.appendChild(g);
    if (waHref || phoneHref) {
      var c = el('div', 'tba-welcome__contact');
      c.appendChild(el('p', 'tba-welcome__label', 'Hemen bir uzmanla konuşmak isterseniz'));
      var row = el('div', 'tba-actions');
      if (waHref) row.appendChild(pill("WhatsApp'tan Yaz", waHref + '?text=' + encodeURIComponent('Merhaba, bilgi almak istiyorum.'), 'wa', true));
      if (phoneHref) row.appendChild(pill('Ara', phoneHref, 'phone', false));
      c.appendChild(row);
      w.appendChild(c);
    }
    log.appendChild(w);
  }

  function renderItem(item, live) {
    var wrap = el('div', 'tba-msg tba-msg--' + (item.kind === 'user' ? 'user' : item.kind === 'error' ? 'bot tba-msg--error' : 'bot'));
    if (item.text) wrap.appendChild(el('div', 'tba-bubble', item.text));
    if (item.kind === 'bot') {
      if (item.cards && item.cards.length) wrap.appendChild(renderCards(item.cards, item.listing, item.lang));
      else if (item.listing && item.listing.url) {
        var la = renderActions([{ type: 'link', label: item.listing.label, url: item.listing.url }]);
        if (la) wrap.appendChild(la);
      }
      var acts = renderActions(item.actions || [], item.lang);
      if (acts) wrap.appendChild(acts);
      if (item.choices && item.choices.length && !item.used) wrap.appendChild(renderChoices(item));
    }
    log.appendChild(wrap);
    if (live) scrollEnd(item.kind === 'bot' ? wrap : null);
    return wrap;
  }

  function renderCards(cards, listing, lang) {
    var en = lang === 'en';
    var row = el('div', 'tba-shelf');
    cards.slice(0, 6).forEach(function (c) {
      var url = safeUrl(c.url);
      if (!url) return;
      var card = el('div', 'tba-item');
      var media = el('a', 'tba-item__media');
      media.href = url;
      if (c.image) { var img = el('img'); img.src = c.image; img.alt = ''; img.loading = 'lazy'; media.appendChild(img); }
      var badges = Array.isArray(c.badges) ? c.badges : (c.badge ? [c.badge] : []);
      if (badges.length) {
        var bw = el('div', 'tba-item__badges');
        badges.forEach(function (b) { bw.appendChild(el('span', 'tba-badge' + (/onay/i.test(b) ? ' tba-badge--ok' : ''), tc(b))); });
        media.appendChild(bw);
      }
      card.appendChild(media);
      var body = el('div', 'tba-item__body');
      if (c.brand) body.appendChild(el('span', 'tba-item__brand', c.brand));
      var name = el('a', 'tba-item__name', c.name || ''); name.href = url; body.appendChild(name);
      if (c.meta) body.appendChild(el('span', 'tba-item__meta', c.meta));
      body.appendChild(el('span', 'tba-item__price' + (c.price ? '' : ' tba-item__price--ask'), c.price || 'Fiyatı sorunuz'));
      var acts = el('div', 'tba-item__actions');
      var view = el('a', 'tba-link');
      view.href = url;
      view.appendChild(document.createTextNode(en ? 'View' : 'İncele'));
      var vi = el('span'); vi.innerHTML = icon('arrow'); view.appendChild(vi);
      acts.appendChild(view);
      var wa = safeUrl(c.wa);
      if (wa) {
        var price = pill(en ? 'Get Price' : 'Fiyat Al', wa, 'wa', true);
        price.className += ' tba-pill--sm';
        acts.appendChild(price);
      }
      body.appendChild(acts);
      card.appendChild(body);
      row.appendChild(card);
    });
    if (listing && safeUrl(listing.url)) {
      var all = el('a', 'tba-item tba-item--all');
      all.href = safeUrl(listing.url);
      var ic = el('span'); ic.innerHTML = icon('arrow'); all.appendChild(ic);
      all.appendChild(el('span', 'tba-all__title', en ? 'See All' : 'Tümünü Gör'));
      var n = typeof listing.count === 'number' ? listing.count : parseInt(String(listing.label || '').replace(/\D+/g, ' ').trim().split(' ')[0], 10);
      if (n > 0) all.appendChild(el('span', 'tba-all__sub', n + (en ? ' products' : ' ürün')));
      row.appendChild(all);
    }
    return row;
  }

  // Sitedeki hap buton: yazı solda, ikon sağda daire içinde
  function pill(label, url, kind, external) {
    var n = el('a', 'tba-pill' + (kind === 'wa' ? ' tba-pill--wa' : ''));
    n.href = url;
    if (external || (!/^tel:/.test(url) && !sameSite(url))) { n.target = '_blank'; n.rel = 'noopener'; }
    n.appendChild(el('span', 'tba-pill__text', tc(label)));
    var ic = el('span', 'tba-pill__icon');
    ic.innerHTML = icon(kind);
    n.appendChild(ic);
    return n;
  }

  function renderActions(actions, lang) {
    var en = lang === 'en';
    var EN = { price_request: 'Get a Price', whatsapp_write: 'WhatsApp', phone: 'Call', service_form: 'Service Request' };
    var list = actions.filter(function (a) { return a && safeUrl(a.url) && a.label; });
    if (!list.length) return null;
    var row = el('div', 'tba-actions');
    list.forEach(function (a) {
      var isWa = a.type === 'price_request' || a.type === 'whatsapp_write';
      var kind = isWa ? 'wa' : a.type === 'phone' ? 'phone' : a.type === 'service_form' ? 'form' : 'arrow';
      var label = en && EN[a.type] ? EN[a.type] : (a.type === 'phone' ? 'Ara' : a.label);
      row.appendChild(pill(label, safeUrl(a.url), kind, false));
    });
    return row;
  }

  function renderChoices(item) {
    var row = el('div', 'tba-choices');
    item.choices.forEach(function (c) {
      var b = el('button', 'tba-choice' + (c.ghost ? ' tba-choice--ghost' : ''));
      b.type = 'button';
      b.appendChild(el('span', 'tba-choice__label', tc(c.label)));
      if (typeof c.count === 'number') b.appendChild(el('span', 'tba-choice__count', String(c.count)));
      var chev = el('span', 'tba-choice__chev');
      chev.innerHTML = '<svg class="ti ti-chevron-right" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6l-6 6" /></svg>';
      b.appendChild(chev);
      b.addEventListener('click', function () {
        if (busy) return;
        item.used = true;
        row.classList.add('is-used');
        if (c.select) ask(c.label, c.select); else ask(c.send || c.label, null);
      });
      row.appendChild(b);
    });
    return row;
  }

  function renderAll() {
    log.innerHTML = '';
    if (!thread.items.length) renderWelcome();
    thread.items.forEach(function (it) { renderItem(it, false); });
    log.scrollTop = log.scrollHeight;
  }

  // ------------------------------------------------------------ gönderme
  function setBusy(v) {
    busy = v;
    sendBtn.disabled = v || !input.value.trim();
    input.setAttribute('aria-busy', v ? 'true' : 'false');
  }

  function ask(text, select) {
    text = String(text || '').trim();
    if (busy || (!text && !select)) return;
    var welcome = log.querySelector('.tba-welcome');
    if (welcome) welcome.remove();
    thread.items.forEach(function (it) { if (it.choices) it.used = true; });
    log.querySelectorAll('.tba-choices').forEach(function (n) { n.classList.add('is-used'); });
    if (text) {
      var u = { kind: 'user', text: text };
      thread.items.push(u);
      renderItem(u, true);
    }
    save();
    setBusy(true);
    var typing = el('div', 'tba-typing');
    typing.setAttribute('aria-label', 'Asistan yazıyor');
    typing.innerHTML = '<span></span><span></span><span></span>';
    log.appendChild(typing);
    scrollEnd(null);

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        message: text.slice(0, 600),
        select: select || null,
        state: thread.state || {},
        history: thread.history.slice(-10),
        page_path: location.pathname,
        cid: thread.cid
      })
    }).then(function (r) {
      if (r.status === 429) return { message: 'Çok hızlı mesaj gönderildi. Lütfen bir dakika sonra tekrar deneyin.', error: true };
      return r.json();
    }).then(function (d) {
      typing.remove();
      d = d || {};
      var item = {
        kind: d.error || d.ok === false ? 'error' : 'bot',
        text: String(d.message || 'Şu anda yanıt veremiyorum. WhatsApp\'tan bize yazabilirsiniz.'),
        cards: Array.isArray(d.cards) ? d.cards : [],
        actions: Array.isArray(d.actions) ? d.actions : [],
        choices: Array.isArray(d.choices) ? d.choices : [],
        listing: d.listing && d.listing.url ? d.listing : null,
        lang: d.lang || (d.state && d.state.lang) || 'tr'
      };
      if (item.kind === 'error' && waHref) item.actions = [{ type: 'whatsapp_write', label: "WhatsApp'tan yaz", url: waHref }];
      if (d.state && typeof d.state === 'object' && !Array.isArray(d.state)) thread.state = d.state;
      if (d.cid) thread.cid = d.cid;
      if (text) thread.history.push({ role: 'user', text: text.slice(0, 600) });
      thread.history.push({ role: 'assistant', text: item.text.slice(0, 600) });
      thread.history = thread.history.slice(-10);
      thread.items.push(item);
      save();
      renderItem(item, true);
    }).catch(function () {
      typing.remove();
      var item = { kind: 'error', text: 'Bağlantı kurulamadı. Tekrar deneyebilir veya WhatsApp\'tan bize yazabilirsiniz.', actions: waHref ? [{ type: 'whatsapp_write', label: "WhatsApp'tan yaz", url: waHref }] : [] };
      thread.items.push(item);
      renderItem(item, true);
    }).then(function () {
      setBusy(false);
      if (!mobile.matches) input.focus();
    });
  }

  // ------------------------------------------------------------ panel aç/kapa
  function fitMobile() {
    if (!mobile.matches || panel.hidden) return;
    var vv = window.visualViewport;
    panel.style.setProperty('--tba-vh', (vv ? vv.height : window.innerHeight) + 'px');
    if (vv) panel.style.transform = 'translateY(' + vv.offsetTop + 'px)';
  }
  function open(silent) {
    if (!panel.hidden) return;
    panel.hidden = false;
    dockBtn.setAttribute('aria-expanded', 'true');
    hideLabel();
    try { localStorage.setItem(OPEN_KEY, '1'); sessionStorage.setItem(OPEN_NOW, '1'); } catch (e) {}
    if (mobile.matches) { document.documentElement.classList.add('tba-lock'); fitMobile(); }
    renderAll();
    if (!silent) {
      track();
      if (!mobile.matches) window.setTimeout(function () { input.focus(); }, 60);
    }
  }
  function close() {
    if (panel.hidden) return;
    panel.hidden = true;
    dockBtn.setAttribute('aria-expanded', 'false');
    document.documentElement.classList.remove('tba-lock');
    panel.style.transform = '';
    try { sessionStorage.removeItem(OPEN_NOW); } catch (e) {}
    dockBtn.focus();
  }
  function reset() {
    thread = { cid: newCid(), state: {}, history: [], items: [], at: Date.now() };
    save();
    renderAll();
    input.focus();
  }
  function track() {
    try {
      var vid = '';
      try { vid = localStorage.getItem('tb_vid') || ''; } catch (e) {}
      var body = JSON.stringify({ label: 'Terazibul Asistanı', vid: vid, path: location.pathname || '', lang: (document.documentElement.lang || 'tr').slice(0, 2).toLowerCase() });
      if (navigator.sendBeacon && navigator.sendBeacon('/api/analytics/click', new Blob([body], { type: 'application/json' }))) return;
      fetch('/api/analytics/click', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'same-origin' }).catch(function () {});
    } catch (e) {}
  }

  // Tek seferlik "Merhaba" baloncuğu
  function hideLabel() { if (label) { label.classList.remove('is-on'); label.hidden = true; } }
  function maybeHello() {
    if (!label) return;
    var seen = false;
    try { seen = localStorage.getItem(OPEN_KEY) === '1' || localStorage.getItem(HELLO_KEY) === '1'; } catch (e) { seen = true; }
    if (seen) return;
    window.setTimeout(function () {
      if (!panel.hidden) return;
      var hello = 'Merhaba', i = 0;
      var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      label.textContent = reduce ? hello : '';
      label.hidden = false;
      window.requestAnimationFrame(function () { label.classList.add('is-on'); });
      try { localStorage.setItem(HELLO_KEY, '1'); } catch (e) {}
      (function type() {
        if (reduce || label.hidden) return;
        i += 1;
        label.textContent = hello.slice(0, i);
        if (i < hello.length) window.setTimeout(type, 48);
      })();
      window.setTimeout(hideLabel, 5000);
    }, 10000);
  }

  // ------------------------------------------------------------ olaylar
  dockBtn.addEventListener('click', function () { if (panel.hidden) open(false); else close(); });
  if (label) label.addEventListener('click', function () { open(false); });
  root.querySelector('[data-tba-close]').addEventListener('click', close);
  root.querySelector('[data-tba-reset]').addEventListener('click', reset);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) close(); });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var v = input.value;
    if (!v.trim()) return;
    input.value = '';
    autosize();
    ask(v, null);
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit')); }
  });
  function autosize() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 112) + 'px';
    sendBtn.disabled = busy || !input.value.trim();
  }
  input.addEventListener('input', autosize);
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', fitMobile);
    window.visualViewport.addEventListener('scroll', fitMobile);
  }
  // Sohbetten bir sayfaya gidilince (ör. "İncele") yeni sayfa kapalı açılır; sohbet saklı, simgeye basınca devam eder
  log.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a');
    if (a && sameSite(a.href) && !/^tel:/.test(a.href) && a.target !== '_blank') {
      try { sessionStorage.removeItem(OPEN_NOW); } catch (err) {}
    }
  });

  sendBtn.disabled = true;
  var reopen = false;
  try { reopen = sessionStorage.getItem(OPEN_NOW) === '1'; } catch (e) {}
  if (reopen && !mobile.matches) open(true); else maybeHello();
  if (phoneHref) root.setAttribute('data-phone-ready', '1');
})();
