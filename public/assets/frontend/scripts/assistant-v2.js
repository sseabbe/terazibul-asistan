(function () {
  var script = document.currentScript || document.querySelector('script[data-endpoint]');
  var endpoint = script ? script.getAttribute('data-endpoint') : '';
  var support = script ? script.getAttribute('data-support') : '';
  var showDebug = script && script.getAttribute('data-debug') === '1';
  var log = document.getElementById('av2-log');
  var form = document.getElementById('av2-form');
  var input = document.getElementById('av2-input');
  var state = {};
  var history = [];
  var question = '';
  var productIds = [];
  var supportToken = '';
  var busy = false;

  function isListingHref(url) {
    if (typeof url !== 'string' || !url) return false;
    try {
      var path = new URL(url, location.origin).pathname;
      return /\/(urunler|products)(\/|$)/.test(path);
    } catch (e) {
      return false;
    }
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function addUser(text) {
    var row = el('div', 'av2-row av2-row--user');
    var bubble = el('div', 'av2-bubble');
    bubble.appendChild(el('p', '', text));
    row.appendChild(bubble);
    log.appendChild(row);
    log.scrollTop = log.scrollHeight;
  }

  function iconFor(type) {
    if (type === 'phone') return '/assets/frontend/images/icons/button-phone.svg?v=tb360';
    if (type === 'service_form') return '/assets/frontend/images/icons/button-form.svg?v=tb360';
    if (type === 'handoff_whatsapp') return '/assets/frontend/images/icons/button-live-support-white.svg?v=tb360';
    return '/assets/frontend/images/icons/button-arrow.svg?v=tb360';
  }

  function addBot(data) {
    var row = el('div', 'av2-row av2-row--bot');
    var bubble = el('div', 'av2-bubble');
    String(data.message || '').split('\n').forEach(function (line) {
      bubble.appendChild(el('p', '', line));
    });
    row.appendChild(bubble);
    log.appendChild(row);

    var cards = Array.isArray(data.cards) ? data.cards : [];
    if (cards.length) {
      var list = el('div', 'av2-cards');
      cards.forEach(function (card) {
        var item = el('article', 'av2-card');
        if (card.image) {
          var img = document.createElement('img');
          img.src = card.image;
          img.alt = '';
          item.appendChild(img);
        } else {
          item.appendChild(el('span'));
        }
        var body = el('div');
        body.appendChild(el('h2', '', card.name || ''));
        if (card.capacity) body.appendChild(el('p', '', card.capacity));
        if (card.precision) body.appendChild(el('p', '', card.precision));
        if (card.badge_key === 'perfect') {
          var badge = el('span', 'product-badge product-badge--renewed');
          var renewedLabel = (document.documentElement.lang || '').toLowerCase().indexOf('en') === 0
            ? 'Refurbished'
            : '2. El';
          badge.appendChild(el('span', 'product-badge-label', renewedLabel));
          body.appendChild(badge);
        } else if (card.badge) {
          body.appendChild(el('span', 'product-badge', card.badge));
        }
        if (card.url) {
          var link = el('a', 'av2-card-link', 'Ürünü İncele');
          link.href = card.url;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          body.appendChild(link);
        }
        item.appendChild(body);
        list.appendChild(item);
      });
      log.appendChild(list);
    }

    if (data.more && data.more.label && isListingHref(data.more.url)) {
      var more = el('a', 'av2-more', data.more.label);
      more.href = data.more.url;
      more.target = '_self';
      log.appendChild(more);
    }

    if (Array.isArray(data.actions) && data.actions.length) {
      var actions = el('div', 'av2-actions');
      data.actions.forEach(function (action) {
        if (!action || !action.label) return;
        if (action.type === 'phone_lead') {
          return;
        }
        if (action.type === 'whatsapp_write' && /^https:\/\/wa\.me\//.test(action.url || '')) {
          var wa = el('a', 'av2-action av2-action--wa', action.label || "WhatsApp'tan Yaz");
          wa.href = action.url;
          wa.target = '_blank';
          wa.rel = 'noopener noreferrer';
          actions.appendChild(wa);
          return;
        }
        if (action.type === 'handoff_chat' || action.type === 'handoff_whatsapp') return;
        var role = action.role === 'primary' ? 'primary' : 'secondary';
        var node;
        if (action.url && action.type !== 'handoff_whatsapp' && action.type !== 'handoff_chat') {
          node = el('a', 'av2-action av2-action--' + role, action.label);
          node.href = action.url;
          if (action.type === 'phone' || action.type === 'service_form') {
            node.target = '_blank';
            node.rel = 'noopener noreferrer';
          }
        } else {
          node = el('button', 'av2-action av2-action--' + role, action.label);
          node.type = 'button';
          node.setAttribute('data-act', action.type || '');
          if (action.prompt) node.setAttribute('data-prompt', action.prompt);
        }
        if (action.type !== 'prompt') {
          var icon = document.createElement('img');
          icon.alt = '';
          icon.src = iconFor(action.type);
          node.appendChild(icon);
        }
        actions.appendChild(node);
      });
      log.appendChild(actions);
    }

    if (showDebug && data.debug) {
      var details = el('details', 'av2-debug');
      details.appendChild(el('summary', '', 'Tur kaydı'));
      details.appendChild(el('pre', '', JSON.stringify(data.debug, null, 2)));
      log.appendChild(details);
    }

    if (data.state) state = data.state;
    productIds = Array.isArray(data.product_ids) ? data.product_ids : productIds;
    log.scrollTop = log.scrollHeight;
  }

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (res) {
      if (res.status === 429) {
        return { message: 'Çok hızlı soru gönderildi. Bir dakika sonra yeniden deneyin.', cards: [], actions: [] };
      }
      return res.json();
    });
  }

  function send(text, ui) {
    var raw = String(text == null ? '' : text).trim();
    if ((!raw && !ui) || busy || !endpoint) return;
    if (raw) {
      question = raw;
      addUser(raw);
      history.push({ role: 'user', text: raw.slice(0, 400) });
    }
    input.value = '';
    busy = true;
    post(endpoint, {
      message: (raw || question).slice(0, 500),
      ui_action: ui || '',
      state: state,
      history: history.slice(-8),
      product_ids: productIds
    }).then(function (data) {
      data = data || { message: 'Cevap alınamadı.' };
      addBot(data);
      if (data.message) history.push({ role: 'assistant', text: String(data.message).slice(0, 400) });
      if (history.length > 8) history = history.slice(-8);
    }).catch(function () {
      addBot({ message: 'Bağlantı kurulamadı. Yeniden deneyin.' });
    }).then(function () {
      busy = false;
      input.focus();
    });
  }

  function openWhatsapp() {
    if (!support) return;
    post(support, {
      action: 'whatsapp',
      question: question,
      product_ids: productIds,
      history: history.slice(-8)
    }).then(function (data) {
      var url = data && data.whatsapp_url;
      if (typeof url === 'string' && /^https:\/\/wa\.me\//.test(url)) {
        window.open(url, '_blank', 'noopener');
      }
    });
  }

  function renderPhoneLead() {
    if (log.querySelector('.av2-phone')) return;
    var phoneForm = el('form', 'av2-phone');
    var field = document.createElement('input');
    field.type = 'tel';
    field.name = 'phone';
    field.setAttribute('autocomplete', 'tel');
    field.placeholder = '05xx xxx xx xx';
    field.maxLength = 18;
    var button = el('button', '', 'Numaramı Bırak');
    button.type = 'submit';
    var note = el('p', '', 'Numaranızı yalnızca bu talebinizle ilgili size dönüş yapmak için kullanacağız.');
    phoneForm.appendChild(field);
    phoneForm.appendChild(button);
    phoneForm.appendChild(note);
    log.appendChild(phoneForm);
    log.scrollTop = log.scrollHeight;
    phoneForm.addEventListener('submit', function (event) {
      event.preventDefault();
      event.stopPropagation();
      if (!support) return;
      var popup = window.open('about:blank', '_blank');
      post(support, {
        action: 'lead',
        phone: field.value,
        question: question,
        product_ids: productIds,
        history: history.slice(-8)
      }).then(function (data) {
        var url = data && data.whatsapp_url;
        if (!data || !data.ok || typeof url !== 'string' || !/^https:\/\/wa\.me\//.test(url)) {
          if (popup && !popup.closed) popup.close();
          addBot({ message: (data && data.message) || 'Telefon numarası geçersiz.' });
          return;
        }
        phoneForm.remove();
        addUser(field.value);
        var opened = false;
        try {
          if (popup && !popup.closed) {
            popup.location = url;
            opened = true;
          }
        } catch (e) {}
        if (opened) addBot({ message: 'WhatsApp açıldı. Mesajınız hazır, gönderdiğinizde ekibimize ulaşır.' });
        else {
          addBot({ message: 'Mesajınız hazır. Göndermek için WhatsApp bağlantısına basın.' });
          var link = el('a', 'av2-action av2-action--primary', "WhatsApp'ta gönder");
          link.href = url;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          log.appendChild(link);
        }
      });
    });
  }

  function startChat() {
    if (!support) return;
    post(support, {
      action: 'start',
      question: question,
      note: '',
      product_ids: productIds,
      history: history.slice(-8)
    }).then(function (data) {
      if (data && data.token) supportToken = data.token;
      addBot({ message: (data && data.message) || 'Sorunuzu ilettik.' });
    });
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    send(input.value, '');
  });

  input.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' || event.shiftKey) return;
    event.preventDefault();
    send(input.value, '');
  });

  log.addEventListener('click', function (event) {
    var prompt = event.target.closest('[data-prompt]');
    if (prompt && prompt.getAttribute('data-act') !== 'compare') {
      send(prompt.getAttribute('data-prompt') || '', '');
      return;
    }
    var act = event.target.closest('[data-act]');
    if (!act) return;
    var type = act.getAttribute('data-act');
    if (type === 'compare') {
      send(act.getAttribute('data-prompt') || 'Teknik özellikleri karşılaştır', '');
      return;
    }
    if (type === 'expert') {
      send('', 'expert');
      return;
    }
    if (type === 'handoff_whatsapp') {
      openWhatsapp();
      return;
    }
    if (type === 'handoff_chat') {
      startChat();
    }
  });
})();
