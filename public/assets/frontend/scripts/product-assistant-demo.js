(function () {
  var script = document.currentScript || document.querySelector('script[data-endpoint]');
  var endpoint = script ? script.getAttribute('data-endpoint') : '';
  var log = document.getElementById('pa-log');
  var form = document.getElementById('pa-form');
  var input = document.getElementById('pa-input');
  var previousIds = [];
  var priorMessage = '';
  var priorTopic = '';
  var rankingMemory = null;
  var turns = [];
  var lastProductQuery = '';
  var shownCount = 0;
  var lastUserText = '';
  var busy = false;

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function blankLink(anchor) {
    anchor.target = '_blank';
    anchor.rel = 'noopener noreferrer';
    return anchor;
  }

  function fitComposer() {
    input.style.height = '56px';
    var height = input.scrollHeight;
    if (height > 144) height = 144;
    input.style.height = height + 'px';
  }

  function revealTop(node) {
    if (!node) return;
    var top = node.getBoundingClientRect().top - log.getBoundingClientRect().top + log.scrollTop;
    log.scrollTop = Math.max(0, top - 8);
  }

  function appendMessage(parent, text) {
    var source = String(text == null ? '' : text);
    var pattern = /https?:\/\/[^\s<>"']+/gi;
    var last = 0;
    var match;
    while ((match = pattern.exec(source))) {
      if (match.index > last) {
        parent.appendChild(document.createTextNode(source.slice(last, match.index)));
      }
      var raw = match[0];
      var href = raw.replace(/[),.;!?]+$/g, '');
      var trailing = raw.slice(href.length);
      var link = blankLink(document.createElement('a'));
      link.href = href;
      link.textContent = href;
      parent.appendChild(link);
      if (trailing) parent.appendChild(document.createTextNode(trailing));
      last = match.index + raw.length;
    }
    if (last < source.length || source === '') {
      parent.appendChild(document.createTextNode(source.slice(last)));
    }
  }

  function addUser(text) {
    var row = el('div', 'pa-row pa-row--user');
    var bubble = el('div', 'pa-bubble');
    var paragraph = el('p');
    appendMessage(paragraph, text);
    bubble.appendChild(paragraph);
    row.appendChild(bubble);
    log.appendChild(row);
  }

  function addBot(data) {
    var row = el('div', 'pa-row pa-row--bot');
    var wrap = el('div', 'pa-bubble');
    var paragraph = el('p');
    appendMessage(paragraph, data.message || '');
    wrap.appendChild(paragraph);
    row.appendChild(wrap);
    log.appendChild(row);

    var cards = Array.isArray(data.cards) ? data.cards.slice(0, 4) : [];
    if (cards.length) {
      var list = el('div', 'pa-cards');
      cards.forEach(function (card) {
        var item = el('div', 'pa-card');
        if (card.image) {
          var img = document.createElement('img');
          img.src = card.image;
          img.alt = '';
          item.appendChild(img);
        } else {
          item.appendChild(el('span', 'pa-card-ph'));
        }
        var body = el('div');
        body.appendChild(el('h2', '', card.name || ''));
        if (card.meta) body.appendChild(el('p', '', card.meta));
        if (card.url || card.badge) {
          var actions = el('div', 'pa-card-actions');
          if (card.url) {
            var link = blankLink(el('a', 'pa-link', 'Ürünü İncele'));
            link.href = card.url;
            actions.appendChild(link);
          }
          if (card.badge) {
            var mark = el('span', 'pa-mark');
            var icon = document.createElement('img');
            icon.src = '/assets/frontend/images/icons/ticari-onayli-m.svg';
            icon.width = 26;
            icon.height = 26;
            icon.alt = '';
            mark.appendChild(icon);
            actions.appendChild(mark);
          }
          body.appendChild(actions);
        }
        item.appendChild(body);
        list.appendChild(item);
      });
      log.appendChild(list);
    }

    if (data.more && data.more.url) {
      var more = blankLink(el('a', 'pa-more', data.more.label || 'Diğer ürünleri görüntüle'));
      more.href = data.more.url;
      log.appendChild(more);
    } else if (data.more && data.more.label) {
      var moreButton = el('button', 'pa-more', data.more.label);
      moreButton.type = 'button';
      moreButton.setAttribute('data-prompt', 'diğer ürünleri göster');
      log.appendChild(moreButton);
    }

    if (Array.isArray(data.actions)) {
      if (data.actions.length) renderActions(data.actions);
    } else if (data.whatsapp && data.whatsapp.url) {
      var wa = blankLink(el('a', 'pa-wa', "WhatsApp'tan Yazın"));
      wa.href = data.whatsapp.url;
      log.appendChild(wa);
    }

    if (Array.isArray(data.prompts) && data.prompts.length) {
      var prompts = el('div', 'pa-prompts pa-prompts-inline');
      data.prompts.forEach(function (prompt) {
        var button = el('button', '', prompt);
        button.type = 'button';
        button.setAttribute('data-prompt', prompt);
        prompts.appendChild(button);
      });
      log.appendChild(prompts);
    }

    previousIds = Array.isArray(data.product_ids) ? data.product_ids.slice(0, 8) : [];
    priorMessage = lastUserText;
    if (data.topic) priorTopic = data.topic;
    if (Object.prototype.hasOwnProperty.call(data, 'memory')) rankingMemory = data.memory;
    revealTop(row);
  }

  function actionIcon(type) {
    if (type === 'phone') return '/assets/frontend/images/icons/button-phone.svg?v=tb360';
    if (type === 'service_form') return '/assets/frontend/images/icons/button-form.svg?v=tb360';
    if (type === 'whatsapp') return '/assets/frontend/images/icons/button-live-support-white.svg?v=tb360';
    return '/assets/frontend/images/icons/button-arrow.svg?v=tb360';
  }

  function renderActions(actions) {
    var wrap = el('div', 'pa-actions');
    actions.forEach(function (action) {
      if (!action || !action.url || !action.label) return;
      var role = action.role === 'primary' ? 'primary' : 'secondary';
      var tone = action.type === 'whatsapp' ? ' pa-action--whatsapp' : '';
      var link = blankLink(el('a', 'pa-action pa-action--' + role + tone));
      link.href = action.url;
      link.appendChild(document.createTextNode(action.label));
      var icon = document.createElement('img');
      icon.className = 'pa-action__icon';
      icon.alt = '';
      icon.src = actionIcon(action.type);
      link.appendChild(icon);
      wrap.appendChild(link);
    });
    log.appendChild(wrap);
  }

  function hideTyping() {
    var node = document.getElementById('pa-typing');
    if (node) node.remove();
  }

  function showTyping() {
    hideTyping();
    var row = el('div', 'pa-row pa-row--bot');
    row.id = 'pa-typing';
    var bubble = el('div', 'pa-bubble pa-typing');
    bubble.setAttribute('aria-label', 'yazıyor');
    bubble.appendChild(el('span', 'pa-typing-word', 'yazıyor'));
    var dots = el('span', 'pa-typing-dots');
    dots.setAttribute('aria-hidden', 'true');
    bubble.appendChild(dots);
    row.appendChild(bubble);
    log.appendChild(row);
    revealTop(row);
  }

  function setBusy(state) {
    busy = state;
    input.disabled = state;
    form.querySelector('button').disabled = state;
  }

  function isMoreAsk(text) {
    return /diğer ürün|diger urun|ürün daha göster|daha fazla ürün/i.test(text);
  }

  function send(text) {
    var raw = String(text == null ? '' : text);
    if (!raw.trim() || busy || !endpoint) return;
    text = raw;
    var offset = 0;
    var outbound = text;
    if (isMoreAsk(text) && lastProductQuery) {
      outbound = lastProductQuery;
      offset = shownCount;
    }
    lastUserText = text;
    var start = document.getElementById('pa-start');
    var prompts = document.getElementById('pa-prompts');
    if (start) start.remove();
    if (prompts) prompts.remove();
    addUser(text);
    input.value = '';
    fitComposer();
    setBusy(true);
    showTyping();

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        message: outbound.slice(0, 500),
        previous_product_ids: previousIds,
        prior_message: priorMessage,
        prior_topic: priorTopic,
        offset: offset,
        memory: rankingMemory,
        history: turns.slice(-6)
      })
    }).then(function (res) {
      if (res.status === 429) {
        return { message: 'Çok hızlı soru gönderildi. Bir dakika sonra yeniden deneyin.', cards: [] };
      }
      return res.json();
    }).then(function (data) {
      hideTyping();
      data = data || { message: 'Cevap alınamadı.' };
      if (data.topic === 'product') {
        if (offset === 0) {
          lastProductQuery = outbound;
          shownCount = (data.cards || []).length;
        } else {
          shownCount += (data.cards || []).length;
        }
      }
      addBot(data);
      turns.push({ role: 'user', text: String(text).slice(0, 300) });
      if (data && data.message) turns.push({ role: 'assistant', text: String(data.message).slice(0, 300) });
      if (turns.length > 6) turns = turns.slice(-6);
    }).catch(function () {
      hideTyping();
      addBot({ message: 'Bağlantı kurulamadı. Yeniden deneyin.' });
    }).then(function () {
      setBusy(false);
      input.focus();
    });
  }

  var shiftEnterAt = 0;

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (Date.now() - shiftEnterAt < 100) return;
    send(input.value);
  });

  input.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.keyCode !== 13) return;
    if (event.shiftKey || event.isComposing) {
      shiftEnterAt = Date.now();
      return;
    }
    event.preventDefault();
    send(input.value);
  });

  input.addEventListener('input', fitComposer);

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

  var panel = document.querySelector('.pa-panel');

  function fitSheet() {
    if (!panel) return;
    if (!window.matchMedia('(max-width: 640px)').matches) {
      panel.style.top = '';
      panel.style.left = '';
      panel.style.right = '';
      panel.style.bottom = '';
      panel.style.width = '';
      panel.style.maxWidth = '';
      panel.style.height = '';
      panel.style.position = '';
      return;
    }
    var vv = window.visualViewport;
    var top = vv ? vv.offsetTop : 0;
    var left = vv ? vv.offsetLeft : 0;
    var height = vv ? vv.height : window.innerHeight;
    var width = vv ? vv.width : window.innerWidth;
    panel.style.position = 'fixed';
    panel.style.left = left + 'px';
    panel.style.right = 'auto';
    panel.style.bottom = 'auto';
    panel.style.width = width + 'px';
    panel.style.maxWidth = width + 'px';
    panel.style.top = top + 'px';
    panel.style.height = height + 'px';
  }

  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', fitSheet);
    window.visualViewport.addEventListener('scroll', fitSheet);
  }
  window.addEventListener('orientationchange', fitSheet);
  if (input) {
    input.addEventListener('focus', function () {
      window.requestAnimationFrame(fitSheet);
      window.setTimeout(fitSheet, 300);
    });
  }
  fitSheet();

  if (log) containScroller(log);

  log.addEventListener('click', function (event) {
    var button = event.target.closest('button[data-prompt]');
    if (!button) return;
    send(button.getAttribute('data-prompt'));
  });
})();
