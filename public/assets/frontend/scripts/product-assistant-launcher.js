(function () {
  var root = document.querySelector('[data-tb-assistant]');
  if (!root) return;

  var OPEN_KEY = 'tb-assistant-opened';
  // "Merhaba" baloncuğu ziyaretçiye yalnızca bir kez gösterilir (yenilemede/sayfa geçişinde tekrar çıkmaz)
  var HELLO_KEY = 'tb-assistant-hello-shown';
  var THREAD_KEY = 'tb-assistant-thread';
  var CONVERSATION_KEY = 'tb-assistant-conversation';
  var SESSION_COOKIE = 'tb-assistant-on';
  var GREETING = '<div class="tbl-row tbl-row--bot"><div class="tbl-bubble"><p>Merhaba,<br>Size nasıl yardımcı olabilirim?</p></div></div>';
  var HELLO = 'Merhaba';
  var FIRST_DELAY = 10000;
  var HOLD_MS = 5000;
  var REPEAT_MS = 120000;
  var TYPE_MS = 48;

  var dock = root.querySelector('.tbl-dock');
  var launcher = root.querySelector('.tbl-btn');
  var label = root.querySelector('.tbl-label');
  var panel = root.querySelector('.tbl-panel');
  var log = root.querySelector('.tbl-log');
  var form = root.querySelector('.tbl-form');
  var input = root.querySelector('.tbl-input');
  var closeBtn = root.querySelector('.tbl-close');
  var endBtn = root.querySelector('.tbl-end');
  var endConfirm = root.querySelector('.tbl-end-confirm');
  var endNo = root.querySelector('.tbl-end-confirm__btn--no');
  var endYes = root.querySelector('.tbl-end-confirm__btn--yes');
  var endpoint = root.getAttribute('data-endpoint') || '';
  var supportEndpoint = root.getAttribute('data-support') || '';

  var previousIds = [];
  var priorMessage = '';
  var priorTopic = '';
  var rankingMemory = null;
  var assistantFreemode = false;
  var turns = [];
  var lastProductQuery = '';
  var shownCount = 0;
  var lastUserText = '';
  var busy = false;
  var requestSeq = 0;
  var supportToken = '';
  var supportAfter = 0;
  var lastExpert = null;
  var leadSaved = false;
  var conversationKey = '';
  var pollTimer = 0;
  var showTimer = 0;
  var hideTimer = 0;
  var typeTimer = 0;
  var labelToken = 0;

  function openedBefore() {
    try { return localStorage.getItem(OPEN_KEY) === '1'; } catch (e) { return false; }
  }

  function helloShown() {
    try { return localStorage.getItem(HELLO_KEY) === '1'; } catch (e) { return false; }
  }

  function markHelloShown() {
    try { localStorage.setItem(HELLO_KEY, '1'); } catch (e) {}
  }

  function markOpened() {
    try { localStorage.setItem(OPEN_KEY, '1'); } catch (e) {}
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function actionIcon(type) {
    if (type === 'phone') return '/assets/frontend/images/icons/button-phone.svg?v=tb360';
    if (type === 'service_form') return '/assets/frontend/images/icons/button-form.svg?v=tb360';
    if (type === 'whatsapp') return '/assets/frontend/images/icons/button-live-support-white.svg?v=tb360';
    return '/assets/frontend/images/icons/button-arrow.svg?v=tb360';
  }

  function promptChipText(item) {
    if (typeof item === 'string') return item.trim();
    if (item && typeof item === 'object') {
      var fromPrompt = typeof item.prompt === 'string' ? item.prompt.trim() : '';
      var fromLabel = typeof item.label === 'string' ? item.label.trim() : '';
      return fromPrompt || fromLabel;
    }
    return '';
  }

  function collectPromptChips(data) {
    data = data || {};
    var seen = {};
    var out = [];
    function push(text) {
      if (!text || text.length > 96 || seen[text]) return;
      seen[text] = true;
      out.push(text);
    }
    if (Array.isArray(data.chips)) {
      data.chips.forEach(function (item) { push(promptChipText(item)); });
    }
    if (Array.isArray(data.prompts)) {
      data.prompts.forEach(function (item) {
        push(promptChipText(item));
      });
    }
    if (Array.isArray(data.actions)) {
      data.actions.forEach(function (action) {
        if (!action || action.type !== 'prompt') return;
        push(promptChipText(action));
      });
    }
    return out.slice(0, 24);
  }

  function renderPromptChips(chips) {
    if (!chips.length || !log) return;
    var prompts = el('div', 'tbl-chips');
    chips.forEach(function (prompt) {
      var button = el('button', '', prompt);
      button.type = 'button';
      button.setAttribute('data-prompt', prompt);
      prompts.appendChild(button);
    });
    log.appendChild(prompts);
  }

  function renderActions(actions) {
    var wrap = el('div', 'tbl-actions');
    actions.forEach(function (action) {
      if (action && action.type === 'phone_lead') {
        return;
      }
      if (action && action.type === 'prompt') {
        return;
      }
      if (action && action.type === 'handoff_whatsapp' && /^https:\/\/wa\.me\//.test(action.url || '')) {
        var handoffWa = document.createElement('a');
        handoffWa.className = 'btn btn--live-support tbl-ask-wa';
        handoffWa.href = action.url;
        handoffWa.target = '_blank';
        handoffWa.rel = 'noopener noreferrer';
        handoffWa.textContent = action.label || "WhatsApp'tan Yaz";
        wrap.appendChild(handoffWa);
        return;
      }
      if (action && action.type === 'handoff_chat') {
        var chat = el('button', 'tbl-action tbl-action--expert', action.label || 'Bu Sohbette Temsilciyle Görüş');
        chat.type = 'button';
        chat.setAttribute('data-expert', 'chat');
        wrap.appendChild(chat);
        return;
      }
      if (action && action.type === 'whatsapp_write' && /^https:\/\/wa\.me\//.test(action.url || '')) {
        var ask = document.createElement('a');
        ask.className = 'btn btn--live-support tbl-ask-wa';
        ask.href = action.url;
        ask.target = '_blank';
        ask.rel = 'noopener noreferrer';
        ask.textContent = action.label || "WhatsApp'tan Yaz";
        wrap.appendChild(ask);
        return;
      }
      if (action && action.type === 'show_products' && action.url) {
        var listing = el('a', 'tbl-action tbl-action--primary');
        listing.href = action.url;
        if (!isSiteHref(action.url)) {
          listing.target = '_blank';
          listing.rel = 'noopener noreferrer';
        }
        listing.setAttribute('data-close-panel', '1');
        listing.appendChild(document.createTextNode(action.label || 'Ürünleri Gör'));
        var listingIcon = document.createElement('img');
        listingIcon.className = 'tbl-action__icon';
        listingIcon.alt = '';
        listingIcon.src = actionIcon('show_products');
        listing.appendChild(listingIcon);
        wrap.appendChild(listing);
        return;
      }
      if (action && action.type === 'price_request' && action.url) {
        var price = document.createElement('a');
        price.id = 'tbl-price-wa';
        price.className = 'btn btn--live-support tbl-price-wa';
        price.href = action.url;
        price.target = '_blank';
        price.rel = 'noopener noreferrer';
        price.textContent = action.label || 'Hemen Fiyat Al';
        wrap.appendChild(price);
        return;
      }
      if (action && action.tone === 'handoff') {
        var handoff = renderHandoff(action);
        if (handoff) wrap.appendChild(handoff);
        return;
      }
      if (action && action.type === 'prompt' && action.prompt) {
        var choice = el('button', 'tbl-action tbl-action--expert', action.label || 'Teknik Özellikleri Karşılaştır');
        choice.type = 'button';
        choice.setAttribute('data-prompt', action.prompt);
        wrap.appendChild(choice);
        return;
      }
      if (action && action.type === 'expert') {
        var ask = el('button', 'tbl-action tbl-action--expert', action.label || 'Uzmanımıza Sor');
        ask.type = 'button';
        ask.setAttribute('data-expert', 'ask');
        wrap.appendChild(ask);
        return;
      }
      if (!action || !action.url || !action.label) return;
      var role = action.role === 'primary' ? 'primary' : 'secondary';
      var tone = action.type === 'whatsapp' ? ' tbl-action--whatsapp' : '';
      var link = el('a', 'tbl-action tbl-action--' + role + tone);
      link.href = action.url;
      if (action.type !== 'phone' && !isSiteHref(action.url)) {
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
      }
      link.appendChild(document.createTextNode(action.label));
      var icon = document.createElement('img');
      icon.className = 'tbl-action__icon';
      icon.alt = '';
      icon.src = actionIcon(action.type);
      link.appendChild(icon);
      wrap.appendChild(link);
    });
    if (wrap.childNodes.length) log.appendChild(wrap);
  }

  function renderHandoff(action) {
    var secondary = action.role === 'secondary';
    var cls = 'tbl-action tbl-action--expert' + (secondary ? ' tbl-action--expert-alt' : '');
    if (action.type === 'expert_chat' || action.type === 'expert_whatsapp') {
      var button = el('button', cls, action.label || '');
      button.type = 'button';
      button.setAttribute('data-expert', action.type === 'expert_chat' ? 'chat' : 'whatsapp');
      return button;
    }
    if (!action.url || !action.label) return null;
    var link = document.createElement('a');
    link.className = cls;
    link.href = action.url;
    if (action.type !== 'phone' && !isSiteHref(action.url)) {
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
    }
    link.textContent = action.label;
    return link;
  }

  function hideLabelNow() {
    if (!label) return;
    label.classList.remove('is-on', 'is-typing');
    label.setAttribute('hidden', '');
    label.textContent = '';
  }

  function helloBlocked() {
    return !label || openedBefore() || (panel && !panel.hasAttribute('hidden'));
  }

  function queueHello(delay) {
    window.clearTimeout(showTimer);
    if (openedBefore() || helloShown()) return;
    showTimer = window.setTimeout(showHello, delay);
  }

  function finishHello(token) {
    hideTimer = window.setTimeout(function () {
      if (token !== labelToken) return;
      label.classList.remove('is-on', 'is-typing');
      window.setTimeout(function () {
        if (token !== labelToken) return;
        hideLabelNow();
        queueHello(REPEAT_MS);
      }, 280);
    }, HOLD_MS);
  }

  function typeHello(token) {
    var index = 0;
    label.classList.add('is-typing');
    function step() {
      if (token !== labelToken || openedBefore()) return;
      index += 1;
      label.textContent = HELLO.slice(0, index);
      if (index < HELLO.length) {
        typeTimer = window.setTimeout(step, TYPE_MS);
        return;
      }
      label.classList.remove('is-typing');
      finishHello(token);
    }
    step();
  }

  function showHello() {
    if (helloBlocked()) {
      if (!openedBefore()) queueHello(REPEAT_MS);
      return;
    }
    if (helloShown()) return;
    markHelloShown();
    var token = ++labelToken;
    window.clearTimeout(typeTimer);
    window.clearTimeout(hideTimer);
    label.textContent = '';
    label.removeAttribute('hidden');
    window.requestAnimationFrame(function () {
      if (token !== labelToken || helloBlocked()) return;
      label.classList.add('is-on');
      var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce) {
        label.textContent = HELLO;
        finishHello(token);
        return;
      }
      typeHello(token);
    });
  }

  function scheduleHello() {
    if (openedBefore() || helloShown()) return;
    queueHello(FIRST_DELAY);
  }

  function trackAssistantOpen() {
    try {
      var vid = '';
      try { vid = localStorage.getItem('tb_vid') || ''; } catch (e) {}
      var body = JSON.stringify({ label: 'Terazibul Asistanı', vid: vid });
      try {
        if (navigator.sendBeacon) {
          var blob = new Blob([body], { type: 'application/json' });
          if (navigator.sendBeacon('/api/analytics/click', blob)) return;
        }
      } catch (e2) {}
      if (typeof fetch !== 'function') return;
      fetch('/api/analytics/click', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: body,
        credentials: 'same-origin',
        keepalive: true
      }).then(function () {}, function () {});
    } catch (e3) {}
  }

  function openPanel(opts) {
    if (!panel) return;
    var silent = !!(opts && opts.silent);
    var wasClosed = panel.hasAttribute('hidden');
    window.clearTimeout(showTimer);
    window.clearTimeout(hideTimer);
    window.clearTimeout(typeTimer);
    labelToken += 1;
    hideLabelNow();
    markOpened();
    panel.removeAttribute('hidden');
    if (launcher) launcher.setAttribute('aria-expanded', 'true');
    lockSheet();
    if (input && !sheetQuery()) input.focus();
    startPoll();
    persistThread();
    if (wasClosed && !silent) trackAssistantOpen();
  }

  function hideEndConfirm() {
    if (!endConfirm) return;
    endConfirm.hidden = true;
    if (endBtn) endBtn.setAttribute('aria-expanded', 'false');
  }

  function showEndConfirm() {
    if (!endConfirm) return;
    endConfirm.hidden = false;
    if (endBtn) endBtn.setAttribute('aria-expanded', 'true');
  }

  function navigationType() {
    try {
      var entries = performance.getEntriesByType('navigation');
      if (entries && entries[0] && typeof entries[0].type === 'string') return entries[0].type;
    } catch (e) {}
    try {
      if (performance.navigation && performance.navigation.type === 1) return 'reload';
    } catch (e2) {}
    return 'navigate';
  }

  function isSiteHref(url) {
    if (typeof url !== 'string' || !url) return false;
    try {
      var parsed = new URL(url, location.origin);
      if (parsed.origin !== location.origin) return false;
      return /^\/(urun|urunler|products)(\/|$)/.test(parsed.pathname);
    } catch (e) {
      return false;
    }
  }

  function hasSessionCookie() {
    return document.cookie.split(';').some(function (part) {
      return part.trim() === SESSION_COOKIE + '=1';
    });
  }

  function setSessionCookie() {
    document.cookie = SESSION_COOKIE + '=1; path=/; SameSite=Lax';
  }

  function dropLocalThread() {
    try { localStorage.removeItem(CONVERSATION_KEY); } catch (e) {}
    try { localStorage.removeItem(THREAD_KEY); } catch (e) {}
  }

  function clearThreadKeys() {
    conversationKey = '';
    try { sessionStorage.removeItem(CONVERSATION_KEY); } catch (e) {}
    try { sessionStorage.removeItem(THREAD_KEY); } catch (e) {}
    dropLocalThread();
  }

  function persistThread() {
    if (!log) return;
    try {
      var payload = JSON.stringify({
        html: log.innerHTML,
        previousIds: previousIds,
        priorMessage: priorMessage,
        priorTopic: priorTopic,
        rankingMemory: rankingMemory,
        assistantFreemode: assistantFreemode,
        turns: turns,
        lastProductQuery: lastProductQuery,
        shownCount: shownCount,
        lastUserText: lastUserText,
        supportToken: supportToken,
        supportAfter: supportAfter,
        leadSaved: leadSaved,
        conversationKey: conversationId(),
        scroll: log.scrollTop,
        open: panel && !panel.hasAttribute('hidden')
      });
      sessionStorage.setItem(THREAD_KEY, payload);
      localStorage.setItem(THREAD_KEY, payload);
      setSessionCookie();
    } catch (e) {}
  }

  function readThreadRaw() {
    var raw = '';
    try { raw = sessionStorage.getItem(THREAD_KEY) || ''; } catch (e) {}
    if (!raw) {
      try { raw = localStorage.getItem(THREAD_KEY) || ''; } catch (e2) {}
    }
    return raw;
  }

  function restoreThread() {
    if (!log) return;
    var raw = readThreadRaw();
    if (!raw) return;
    var data;
    try { data = JSON.parse(raw); } catch (e) { return; }
    if (!data || typeof data.html !== 'string' || data.html.indexOf('tbl-row') === -1) return;
    ensureCardStyles();
    log.innerHTML = data.html;
    var typing = document.getElementById('tbl-typing');
    if (typing) typing.remove();
    previousIds = Array.isArray(data.previousIds) ? data.previousIds : [];
    priorMessage = typeof data.priorMessage === 'string' ? data.priorMessage : '';
    priorTopic = typeof data.priorTopic === 'string' ? data.priorTopic : '';
    rankingMemory = data.rankingMemory || null;
    assistantFreemode = data.assistantFreemode === true;
    turns = Array.isArray(data.turns) ? data.turns.slice(-6) : [];
    lastProductQuery = typeof data.lastProductQuery === 'string' ? data.lastProductQuery : '';
    shownCount = Number(data.shownCount) || 0;
    lastUserText = typeof data.lastUserText === 'string' ? data.lastUserText : '';
    supportToken = typeof data.supportToken === 'string' ? data.supportToken : '';
    supportAfter = Number(data.supportAfter) || 0;
    leadSaved = data.leadSaved === true;
    if (typeof data.conversationKey === 'string' && /^[a-f0-9]{32}$/.test(data.conversationKey)) {
      conversationKey = data.conversationKey;
    }
    var scroll = Number(data.scroll) || 0;
    window.requestAnimationFrame(function () {
      if (log) log.scrollTop = scroll;
    });
    if (data.open) openPanel({ silent: true });
    persistThread();
  }

  function resetThread() {
    previousIds = [];
    priorMessage = '';
    priorTopic = '';
    rankingMemory = null;
    assistantFreemode = false;
    turns = [];
    lastProductQuery = '';
    shownCount = 0;
    lastUserText = '';
    supportToken = '';
    supportAfter = 0;
    lastExpert = null;
    leadSaved = false;
    clearThreadKeys();
    stopPoll();
    if (log) log.innerHTML = GREETING;
  }

  function closePanel() {
    if (!panel) return;
    hideEndConfirm();
    stopPoll();
    panel.setAttribute('hidden', '');
    unlockSheet();
    persistThread();
    if (launcher) {
      launcher.setAttribute('aria-expanded', 'false');
      if (!sheetQuery()) launcher.focus();
    }
  }

  function togglePanel() {
    if (!panel) return;
    if (panel.hasAttribute('hidden')) openPanel();
    else closePanel();
  }

  if (dock) {
    dock.addEventListener('click', function (event) {
      var target = event.target;
      if (target && target.closest && (target.closest('.tbl-btn') || target.closest('.tbl-label'))) {
        togglePanel();
      }
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', function () {
      closePanel();
    });
  }

  if (endBtn) {
    endBtn.addEventListener('click', function (event) {
      event.stopPropagation();
      if (endConfirm && !endConfirm.hidden) hideEndConfirm();
      else showEndConfirm();
    });
  }

  if (endNo) {
    endNo.addEventListener('click', function (event) {
      event.stopPropagation();
      hideEndConfirm();
    });
  }

  if (endYes) {
    endYes.addEventListener('click', function (event) {
      event.stopPropagation();
      hideEndConfirm();
      resetThread();
      closePanel();
    });
  }

  document.addEventListener('click', function (event) {
    if (!endConfirm || endConfirm.hidden) return;
    if (event.target.closest && event.target.closest('.tbl-head-tools')) return;
    hideEndConfirm();
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' || !panel || panel.hasAttribute('hidden')) return;
    if (endConfirm && !endConfirm.hidden) {
      hideEndConfirm();
      return;
    }
    closePanel();
  });

  var sheetLockY = 0;
  var sheetScreenHeight = 0;
  var sheetPinning = false;

  function sheetQuery() {
    return window.matchMedia('(max-width: 767px)').matches;
  }

  function fitSheet() {
    if (!panel) return;
    if (panel.hasAttribute('hidden') || !sheetQuery()) {
      panel.style.top = '';
      panel.style.left = '';
      panel.style.right = '';
      panel.style.bottom = '';
      panel.style.width = '';
      panel.style.maxWidth = '';
      panel.style.height = '';
      sheetScreenHeight = 0;
      return;
    }
    var vv = window.visualViewport;
    var inner = window.innerHeight;
    var visible = vv ? vv.height : inner;
    var offsetTop = vv ? Math.max(0, vv.offsetTop) : 0;
    if (!sheetScreenHeight) sheetScreenHeight = Math.max(inner, visible);
    var screen = Math.max(sheetScreenHeight, inner, visible);
    panel.style.left = '0px';
    panel.style.right = '0px';
    panel.style.bottom = 'auto';
    panel.style.width = '100%';
    panel.style.maxWidth = '100%';
    // iOS shrinks innerHeight with the keyboard and pans offsetTop, so a
    // small innerHeight gap does not mean the keyboard is gone.
    var keyboardOpen = screen - visible > 80;
    if (!keyboardOpen) {
      sheetScreenHeight = screen;
      panel.style.top = '0px';
      panel.style.height = screen + 'px';
      if (offsetTop > 1 && !sheetPinning) {
        sheetPinning = true;
        window.scrollTo(0, 0);
        window.setTimeout(function () { sheetPinning = false; }, 400);
      }
      return;
    }
    panel.style.top = offsetTop + 'px';
    panel.style.height = visible + 'px';
  }

  function lockSheet() {
    if (!sheetQuery()) {
      fitSheet();
      return;
    }
    sheetLockY = window.scrollY || window.pageYOffset || 0;
    document.documentElement.style.overflow = 'hidden';
    document.body.style.position = 'fixed';
    document.body.style.top = '-' + sheetLockY + 'px';
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';
    fitSheet();
  }

  function unlockSheet() {
    fitSheet();
    if (document.body.style.position !== 'fixed') return;
    document.documentElement.style.overflow = '';
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    window.scrollTo(0, sheetLockY);
  }

  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', fitSheet);
    window.visualViewport.addEventListener('scroll', fitSheet);
  }
  window.addEventListener('orientationchange', function () {
    sheetScreenHeight = 0;
    fitSheet();
  });
  if (input) {
    input.addEventListener('focus', function () {
      window.requestAnimationFrame(fitSheet);
      window.setTimeout(fitSheet, 300);
    });
  }

  function revealTop(node) {
    if (!node || !log) return;
    var top = node.getBoundingClientRect().top - log.getBoundingClientRect().top + log.scrollTop;
    log.scrollTop = Math.max(0, top - 8);
  }

  function addUser(text) {
    var row = el('div', 'tbl-row tbl-row--user');
    var bubble = el('div', 'tbl-bubble');
    bubble.appendChild(el('p', '', text));
    row.appendChild(bubble);
    log.appendChild(row);
    persistThread();
  }

  function isSecondHand(badge) {
    return /2\s*\.?\s*el/i.test(String(badge || ''));
  }

  // V3: önerilen ürün kartları (yatay kaydırmalı)
  function ensureCardStyles() {
    if (document.getElementById('tbl-card-css')) return;
    var css = document.createElement('style');
    css.id = 'tbl-card-css';
    css.textContent = '.tbl-log .tbl-cards{flex:0 0 auto;display:flex !important;flex-direction:row !important;align-items:stretch;flex-wrap:nowrap;gap:8px;overflow-x:auto;overflow-y:hidden;padding:2px 2px 8px;margin:0 0 4px;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;max-width:100%}'
      + '.tbl-log .tbl-cards .tbl-card{flex:0 0 150px;width:150px;max-width:150px;box-sizing:border-box;display:flex;flex-direction:column;gap:4px;padding:8px;border:1px solid rgba(14,13,34,.08);border-radius:14px;background:#fff;color:#0E0D22;text-decoration:none;scroll-snap-align:start}'
      + '.tbl-log .tbl-cards .tbl-card img{display:block;width:100%;height:auto;aspect-ratio:1/1;object-fit:contain;border-radius:10px;background:#F7F7F7}'
      + '.tbl-card b{font-size:12px;line-height:1.35;font-weight:600;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}'
      + '.tbl-card span{font-size:11px;color:rgba(14,13,34,.6)}'
      + '.tbl-card .tbl-card__price{font-size:12px;font-weight:600;color:#0E0D22}'
      + '.tbl-row--bot .tbl-bubble p{white-space:pre-line}'
      + '.tbl-bubble.tbl-links{display:flex;flex-direction:column;padding:4px 12px;background:#fff;border:1px solid rgba(14,13,34,.08)}'
      + '.tbl-links .tbl-link{display:flex;align-items:center;gap:10px;padding:10px 0;color:#0E0D22;font-weight:500;font-size:14px;line-height:1.3;text-decoration:none}'
      + '.tbl-links .tbl-link + .tbl-link{border-top:1px solid rgba(14,13,34,.07)}'
      + '.tbl-links .tbl-link__icon{flex:0 0 28px;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#EEEBFF;color:#4B34F6}'
      + '.tbl-links .tbl-link__icon svg{width:16px;height:16px;display:block}'
      + '.tbl-links .tbl-link--wa .tbl-link__icon{background:#E3F7EA;color:#1A9E4B}'
      + '.tbl-links .tbl-link__text{flex:1 1 auto}'
      + '.tbl-links .tbl-link__arrow{flex:0 0 auto;color:rgba(14,13,34,.4)}'
      + '.tbl-links .tbl-link:hover .tbl-link__text{color:#4B34F6}'
      + '.tbl-card em{align-self:flex-start;font-style:normal;font-size:10px;padding:2px 7px;border-radius:99px;background:#0E0D22;color:#fff}';
    document.head.appendChild(css);
  }

  function renderCards(cards) {
    if (!Array.isArray(cards) || !cards.length || !log) return;
    ensureCardStyles();
    var row = el('div', 'tbl-cards');
    cards.slice(0, 4).forEach(function (card) {
      if (!card || !card.url || !isSiteHref(card.url)) return;
      var a = el('a', 'tbl-card');
      a.href = card.url;
      if (card.image) {
        var img = document.createElement('img');
        img.src = card.image;
        img.alt = '';
        img.loading = 'lazy';
        a.appendChild(img);
      }
      if (card.badge) a.appendChild(el('em', '', card.badge));
      a.appendChild(el('b', '', card.name || ''));
      if (card.meta) a.appendChild(el('span', '', card.meta));
      a.appendChild(el('span', 'tbl-card__price', card.price || 'Fiyat için sorun'));
      row.appendChild(a);
    });
    if (row.childNodes.length) log.appendChild(row);
  }

  function linkIcon(action) {
    var p = '<path d="M5 12l14 0" /><path d="M13 18l6 -6" /><path d="M13 6l6 6" />';
    if (action.type === 'price_request' || action.type === 'whatsapp_write') {
      return '<svg class="ti ti-brand-whatsapp" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9l-5.05 .9" /><path d="M9 10a.5 .5 0 0 0 1 0v-1a.5 .5 0 0 0 -1 0v1a5 5 0 0 0 5 5h1a.5 .5 0 0 0 0 -1h-1a.5 .5 0 0 0 0 1" /></svg>';
    }
    if (action.type === 'phone') p = '<path d="M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5l1.5 -2.5l5 2v4a2 2 0 0 1 -2 2a16 16 0 0 1 -15 -15a2 2 0 0 1 2 -2" />';
    else if (action.type === 'service_form') p = '<path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2" /><path d="M9 9l1 0" /><path d="M9 13l6 0" /><path d="M9 17l6 0" />';
    else if (action.type === 'show_products') p = '<path d="M4 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4" /><path d="M14 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4" /><path d="M4 15a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4" /><path d="M14 15a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4" />';
    else if (action.type === 'page_link') p = '<path d="M9 15l6 -6" /><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" /><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />';
    return '<svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
  }

  // V3: butonlar yerine ayrı bir mesaj baloncuğunda bağlantılar
  function renderLinkBubble(actions) {
    if (!log) return;
    var links = [];
    actions.forEach(function (action) {
      if (!action || !action.url || !action.label) return;
      if (!/^(https?:|tel:|\/)/.test(action.url)) return;
      var a = el('a', 'tbl-link');
      a.href = action.url;
      if (action.type === 'price_request' || action.type === 'whatsapp_write') a.className += ' tbl-link--wa';
      if (!/^tel:/.test(action.url) && !isSiteHref(action.url)) {
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
      }
      var icon = el('span', 'tbl-link__icon');
      icon.innerHTML = linkIcon(action);
      a.appendChild(icon);
      a.appendChild(el('span', 'tbl-link__text', action.label));
      a.appendChild(el('span', 'tbl-link__arrow', '›'));
      if (action.type === 'show_products') a.setAttribute('data-close-panel', '1');
      links.push(a);
    });
    if (!links.length) return;
    var row = el('div', 'tbl-row tbl-row--bot');
    var bubble = el('div', 'tbl-bubble tbl-links');
    links.forEach(function (a) { bubble.appendChild(a); });
    row.appendChild(bubble);
    log.appendChild(row);
  }

  function addBot(data) {
    data = data || {};
    var row = el('div', 'tbl-row tbl-row--bot');
    var wrap = el('div', 'tbl-bubble');
    wrap.appendChild(el('p', '', data.message || ''));
    row.appendChild(wrap);
    log.appendChild(row);
    ensureCardStyles();
    if (data.engine === 'v3') renderCards(data.cards);

    var priceActions = [];
    var listingActions = [];
    var otherActions = [];
    if (data.engine === 'v3' && Array.isArray(data.actions)) {
      renderLinkBubble(data.actions);
    } else if (Array.isArray(data.actions)) {
      data.actions.forEach(function (action) {
        if (action && action.type === 'price_request') priceActions.push(action);
        else if (action && action.type === 'show_products') listingActions.push(action);
        else otherActions.push(action);
      });
      if (listingActions.length) renderActions(listingActions);
      if (otherActions.length) renderActions(otherActions);
    } else if (data.whatsapp && data.whatsapp.url) {
      var wa = el('a', 'tbl-wa', "WhatsApp'tan Yaz");
      wa.href = data.whatsapp.url;
      wa.target = '_blank';
      wa.rel = 'noopener noreferrer';
      log.appendChild(wa);
    }

    if (data.freemode !== true) {
      if (Array.isArray(data.product_ids) && data.product_ids.length) {
        previousIds = data.product_ids.slice(0, 8);
      } else {
        previousIds = [];
      }
    }
    if (data.topic !== 'product') {
      priorTopic = '';
    }

    if (data.more && data.more.label && isListingHref(data.more.url)) {
      var more = el('a', 'tbl-more', data.more.label);
      more.href = data.more.url;
      if (!isSiteHref(data.more.url)) more.target = '_blank';
      log.appendChild(more);
    }

    if (priceActions.length) renderActions(priceActions);

    renderPromptChips(collectPromptChips(data));

    priorMessage = lastUserText;
    if (data.topic) priorTopic = data.topic;
    if (data.expert && typeof data.expert === 'object') lastExpert = data.expert;
    if (data.freemode === true) {
      assistantFreemode = true;
      rankingMemory = {};
      previousIds = [];
      priorMessage = '';
      priorTopic = '';
    } else if (Object.prototype.hasOwnProperty.call(data, 'memory')) {
      rankingMemory = data.memory;
    }
    revealTop(row);
    persistThread();
  }

  function setBusy(state) {
    busy = state;
    if (input) input.disabled = state;
    var submit = form ? form.querySelector('button') : null;
    if (submit) submit.disabled = state;
  }

  function isListingHref(url) {
    if (typeof url !== 'string' || !url) return false;
    try {
      var path = new URL(url, location.origin).pathname;
      return /\/(urunler|products)(\/|$)/.test(path);
    } catch (e) {
      return false;
    }
  }

  function pageBrand() {
    var parts = location.pathname.split('/').filter(Boolean);
    var index = parts.indexOf('urunler');
    return index >= 0 && parts[index + 1] ? parts[index + 1] : '';
  }

  function pageCategory() {
    var parts = location.pathname.split('/').filter(Boolean);
    var index = parts.indexOf('urunler');
    if (index < 0) return '';
    var rest = parts.slice(index + 1).filter(function (part) {
      return /^[a-z0-9-]{2,60}$/.test(part);
    });
    return rest.length ? rest[rest.length - 1] : '';
  }

  function send(text) {
    text = (text || '').trim();
    if (!text || busy || !log) return;
    if (sheetQuery() && input) input.blur();
    if (supportToken) {
      sendSupport(text);
      return;
    }
    if (!endpoint) return;
    var offset = 0;
    var outbound = text;
    lastUserText = text;
    var start = root.querySelector('.tbl-start');
    if (start) start.remove();
    addUser(text);
    if (input) input.value = '';
    var seq = ++requestSeq;
    setBusy(true);
    var typing = el('div', 'tbl-row tbl-row--bot');
    typing.id = 'tbl-typing';
    var bubble = el('div', 'tbl-bubble tbl-typing');
    bubble.setAttribute('aria-label', 'yazıyor');
    bubble.appendChild(el('span', 'tbl-typing-word', 'yazıyor'));
    var dots = el('span', 'tbl-typing-dots');
    dots.setAttribute('aria-hidden', 'true');
    bubble.appendChild(dots);
    typing.appendChild(bubble);
    log.appendChild(typing);
    revealTop(typing);

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        message: outbound.slice(0, 500),
        previous_product_ids: assistantFreemode ? [] : previousIds,
        prior_message: assistantFreemode ? '' : priorMessage,
        prior_topic: assistantFreemode ? '' : priorTopic,
        offset: offset,
        page_brand: pageBrand(),
        page_category: pageCategory(),
        memory: assistantFreemode ? {} : rankingMemory,
        history: assistantFreemode ? [] : turns.slice(-10),
        page_path: location.pathname,
        cid: conversationId()
      })
    }).then(function (res) {
      if (res.status === 429) {
        return { message: 'Çok hızlı soru gönderildi. Bir dakika sonra yeniden deneyin.', cards: [] };
      }
      return res.json();
    }).then(function (data) {
      if (seq !== requestSeq) return;
      data = data || {};
      if (typeof data.message !== 'string' || !String(data.message).trim()) {
        data.message = 'Şu anda bu aramayı tamamlayamadım. İsterseniz tekrar deneyebilir veya ekibimizden destek isteyebilirsiniz.';
      }
      if (data.topic === 'product' && offset === 0) {
        lastProductQuery = outbound;
        shownCount = 0;
      }
      addBot(data);
      if (assistantFreemode) {
        turns = [];
      } else {
        turns.push({ role: 'user', text: String(text).slice(0, 600) });
        if (data && data.message) turns.push({ role: 'assistant', text: String(data.message).slice(0, 600) });
        if (turns.length > 10) turns = turns.slice(-10);
      }
      persistThread();
    }).catch(function () {
      if (seq !== requestSeq) return;
      addBot({ message: 'Bağlantı kurulamadı. Yeniden deneyin.' });
    }).then(function () {
      if (seq !== requestSeq) return;
      var node = document.getElementById('tbl-typing');
      if (node) node.remove();
      setBusy(false);
      if (input && !sheetQuery()) input.focus();
    });
  }

  var shiftEnterAt = 0;

  if (input) {
    input.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter' && event.keyCode !== 13) return;
      if (event.shiftKey || event.isComposing) {
        shiftEnterAt = Date.now();
        return;
      }
      event.preventDefault();
      send(input.value);
    });
  }

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (Date.now() - shiftEnterAt < 100) return;
      send(input ? input.value : '');
    });
  }

  function atScrollEdge(scroller, delta) {
    if (!delta) return false;
    if (delta < 0) return scroller.scrollTop <= 0;
    return scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 1;
  }

  function containScroller(scroller) {
    if (!scroller) return;
    var lastY = 0;

    scroller.addEventListener('wheel', function (event) {
      if (atScrollEdge(scroller, event.deltaY)) event.preventDefault();
    }, { passive: false });

    scroller.addEventListener('touchstart', function (event) {
      if (event.touches && event.touches.length) lastY = event.touches[0].clientY;
    }, { passive: true });

    scroller.addEventListener('touchmove', function (event) {
      if (!event.touches || !event.touches.length) return;
      var y = event.touches[0].clientY;
      var delta = lastY - y;
      lastY = y;
      if (atScrollEdge(scroller, delta) && event.cancelable) event.preventDefault();
    }, { passive: false });
  }

  if (log) {
    log.addEventListener('click', function (event) {
      var link = event.target && event.target.closest ? event.target.closest('a[data-close-panel]') : null;
      if (!link) return;
      closePanel();
    });
    log.addEventListener('submit', function (event) {
      var phoneForm = event.target.closest('.tbl-phone');
      if (!phoneForm) return;
      event.preventDefault();
      var field = phoneForm.querySelector('input[name="phone"]');
      var error = phoneForm.querySelector('.tbl-phone-error');
      var value = field ? field.value : '';
      if (!isCompleteTrPhone(value)) {
        if (error) error.hidden = false;
        if (field) field.focus();
        return;
      }
      if (error) error.hidden = true;
      submitLead(formatTrPhone(value), phoneForm);
    });
    log.addEventListener('input', function (event) {
      var field = event.target.closest('.tbl-phone input[name="phone"]');
      if (!field) return;
      var formatted = formatTrPhone(field.value || '');
      if (field.value !== formatted) field.value = formatted;
    });
    containScroller(log);
    log.addEventListener('scroll', function () {
      window.clearTimeout(log._tbSave);
      log._tbSave = window.setTimeout(persistThread, 200);
    });
    log.addEventListener('click', function (event) {
      var choice = event.target.closest('[data-expert]');
      if (choice) {
        var kind = choice.getAttribute('data-expert');
        if (kind === 'ask' || kind === 'chat' || kind === 'whatsapp') showPhoneLead(true);
        return;
      }
      var button = event.target.closest('button[data-prompt]');
      if (!button) return;
      send(button.getAttribute('data-prompt'));
    });
  }

  function conversationId() {
    if (/^[a-f0-9]{32}$/.test(conversationKey)) return conversationKey;
    try { conversationKey = sessionStorage.getItem(CONVERSATION_KEY) || ''; } catch (e) {}
    if (!/^[a-f0-9]{32}$/.test(conversationKey)) {
      conversationKey = '';
      var bytes = new Uint8Array(16);
      if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(bytes);
      else for (var i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
      for (var j = 0; j < bytes.length; j++) conversationKey += ('0' + bytes[j].toString(16)).slice(-2);
      try { sessionStorage.setItem(CONVERSATION_KEY, conversationKey); } catch (e2) {}
    }
    return conversationKey;
  }

  function leadDigits(value) {
    var raw = String(value || '').trim();
    var digits = raw.replace(/\D/g, '');
    if (digits.indexOf('0090') === 0) digits = digits.slice(4);
    if (digits.indexOf('90') === 0 && (raw.charAt(0) === '+' || digits.length >= 12)) digits = digits.slice(2);
    if (digits.charAt(0) === '5') digits = ('0' + digits).slice(0, 11);
    return digits.slice(0, 11);
  }

  function formatTrPhone(value) {
    var raw = String(value || '');
    var digits = raw.replace(/\D/g, '');
    if (!digits) return '';
    var local = digits;
    if (local.indexOf('90') === 0 && (raw.trim().charAt(0) === '+' || local.length >= 12)) local = local.slice(2);
    if (local.charAt(0) === '5') local = '0' + local;
    if (local.charAt(0) !== '0') return raw.slice(0, 18);
    local = local.slice(0, 11);
    var parts = [];
    if (local.length) parts.push(local.slice(0, Math.min(4, local.length)));
    if (local.length > 4) parts.push(local.slice(4, Math.min(7, local.length)));
    if (local.length > 7) parts.push(local.slice(7, Math.min(9, local.length)));
    if (local.length > 9) parts.push(local.slice(9, 11));
    return parts.join(' ');
  }

  function isCompleteTrPhone(value) {
    return /^05\d{9}$/.test(leadDigits(value));
  }

  function showPhoneLead(withLine) {
    if (leadSaved || !log || log.querySelector('.tbl-phone')) return;
    if (withLine) addBot({ message: 'İsterseniz numaranızı bırakın, ekibimiz size dönüş yapsın.' });
    var phoneForm = el('form', 'tbl-phone');
    var field = document.createElement('input');
    field.type = 'tel';
    field.name = 'phone';
    field.setAttribute('inputmode', 'tel');
    field.setAttribute('autocomplete', 'tel');
    field.maxLength = 18;
    field.placeholder = '05xx xxx xx xx';
    field.setAttribute('aria-label', 'Telefon');
    var button = el('button', '', 'Numaramı Bırak');
    button.type = 'submit';
    var note = el('p', 'tbl-phone-note', 'Numaranızı yalnızca bu talebinizle ilgili size dönüş yapmak için kullanacağız.');
    var privacy = document.createElement('a');
    privacy.href = '/kvkk';
    privacy.target = '_blank';
    privacy.rel = 'noopener noreferrer';
    privacy.textContent = 'KVKK Aydınlatma Metni';
    note.appendChild(document.createTextNode(' '));
    note.appendChild(privacy);
    var error = el('p', 'tbl-phone-error', 'Telefon numaranızı 05xx xxx xx xx biçiminde yazın.');
    error.hidden = true;
    phoneForm.appendChild(field);
    phoneForm.appendChild(button);
    phoneForm.appendChild(note);
    phoneForm.appendChild(error);
    log.appendChild(phoneForm);
    revealTop(phoneForm);
    persistThread();
    field.focus();
  }

  function submitLead(phone, phoneForm) {
    if (busy || !supportEndpoint || leadSaved) return;
    if (!isCompleteTrPhone(phone || '')) {
      showPhoneLead(false);
      return;
    }
    var popup = window.open('about:blank', '_blank');
    try { if (popup) popup.opener = null; } catch (e) {}
    setBusy(true);
    var expert = lastExpert || {};
    var ids = Array.isArray(expert.product_ids) && expert.product_ids.length ? expert.product_ids : previousIds;
    fetch(supportEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        action: 'lead',
        phone: phone,
        question: String(expert.question || lastUserText || '').slice(0, 500),
        product_ids: Array.isArray(ids) ? ids.slice(0, 4) : [],
        history: turns.slice(-8)
      })
    }).then(function (res) { return res.json(); }).then(function (data) {
      data = data || {};
      var url = typeof data.whatsapp_url === 'string' ? data.whatsapp_url : '';
      if (!data.ok || !/^https:\/\/wa\.me\//.test(url)) {
        if (popup && !popup.closed) popup.close();
        if (phoneForm && phoneForm.parentNode) phoneForm.remove();
        addBot({ message: data.message || 'Şu anda iletemedik. Biraz sonra yeniden deneyin.' });
        showPhoneLead(false);
        return;
      }
      leadSaved = true;
      if (phoneForm && phoneForm.parentNode) phoneForm.remove();
      addUser(formatTrPhone(phone));
      var opened = false;
      try {
        if (popup && !popup.closed) {
          popup.location = url;
          opened = true;
        }
      } catch (e) {
        opened = false;
      }
      if (opened) {
        addBot({ message: 'WhatsApp açıldı. Mesajınız hazır, gönderdiğinizde ekibimize ulaşır.' });
      } else {
        addBot({ message: 'Mesajınız hazır. Göndermek için WhatsApp bağlantısına basın.' });
        var link = el('a', 'tbl-action tbl-action--expert', "WhatsApp'ta gönder");
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        log.appendChild(link);
      }
      persistThread();
    }).catch(function () {
      if (popup && !popup.closed) popup.close();
      addBot({ message: 'Bağlantı kurulamadı. Yeniden deneyin.' });
      showPhoneLead(false);
    }).then(function () {
      setBusy(false);
    });
  }

  function sendSupport(text) {
    addUser(text);
    if (input) input.value = '';
    setBusy(true);
    fetch(supportEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'message', token: supportToken, message: text.slice(0, 2000) })
    }).then(function (res) { return res.json(); }).then(function (data) {
      if (!data || !data.ok) {
        addBot({ message: (data && data.message) || 'Mesaj iletilemedi.' });
      }
      persistThread();
    }).catch(function () {
      addBot({ message: 'Bağlantı kurulamadı. Yeniden deneyin.' });
    }).then(function () {
      setBusy(false);
      if (input && !sheetQuery()) input.focus();
    });
  }

  function addAgentMessage(item) {
    if (!item || !item.id || document.querySelector('[data-support-id="' + item.id + '"]')) return;
    var row = el('div', 'tbl-row tbl-row--bot');
    row.setAttribute('data-support-id', String(item.id));
    var bubble = el('div', 'tbl-bubble');
    bubble.appendChild(el('p', 'tbl-expert-label', 'Ekibimiz yanıtladı'));
    bubble.appendChild(el('p', '', item.text || ''));
    row.appendChild(bubble);
    log.appendChild(row);
    revealTop(row);
    if (item.id > supportAfter) supportAfter = item.id;
    persistThread();
  }

  function pollSupport() {
    if (!supportToken || !supportEndpoint || !panel || panel.hasAttribute('hidden')) return;
    fetch(supportEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'poll', token: supportToken, after_id: supportAfter })
    }).then(function (res) { return res.json(); }).then(function (data) {
      var list = data && Array.isArray(data.messages) ? data.messages : [];
      list.forEach(addAgentMessage);
    }).catch(function () {});
  }

  function startPoll() {
    stopPoll();
    if (!supportToken || !panel || panel.hasAttribute('hidden')) return;
    pollSupport();
    pollTimer = window.setInterval(pollSupport, 12000);
  }

  function stopPoll() {
    if (pollTimer) window.clearInterval(pollTimer);
    pollTimer = 0;
  }

  if (panel) {
    panel.addEventListener('click', function (event) {
      var link = event.target.closest && event.target.closest('a[href]');
      if (!link) return;
      if (isSiteHref(link.getAttribute('href') || '')) {
        link.target = '_self';
        link.removeAttribute('rel');
      }
      persistThread();
    }, true);
  }

  if (navigationType() === 'reload' || !hasSessionCookie()) {
    clearThreadKeys();
    if (log) log.innerHTML = GREETING;
  } else {
    restoreThread();
  }
  setSessionCookie();
  scheduleHello();
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) scheduleHello();
  });
})();
