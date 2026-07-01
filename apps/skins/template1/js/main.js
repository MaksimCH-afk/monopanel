(function () {
  'use strict';

  var PARTNER_OFFER_URL = 'https://money.com/';

  var openPartnerOffer = function () {
    window.open(PARTNER_OFFER_URL, '_blank', 'noopener,noreferrer');
  };

  var shouldSkipPartnerRedirect = function (anchor) {
    if (anchor.closest('.lang-switcher')) {
      return true;
    }
    if (anchor.hasAttribute('data-auth') || anchor.hasAttribute('data-mode')) {
      return true;
    }
    if (anchor.closest('.footer')) {
      return true;
    }
    if (anchor.closest('.socials') || anchor.closest('.author-socials')) {
      return true;
    }
    if (anchor.classList.contains('skip-link')) {
      return true;
    }
    var rawHref = anchor.getAttribute('href');
    if (rawHref === null || rawHref === '') {
      return true;
    }
    if (rawHref === '#') {
      return false;
    }
    if (rawHref.startsWith('#')) {
      return true;
    }
    try {
      var url = new URL(rawHref, window.location.href);
      var protocol = url.protocol.toLowerCase();
      if (protocol === 'mailto:' || protocol === 'tel:' || protocol === 'sms:' || protocol === 'javascript:' || protocol === 'data:') {
        return true;
      }
      if (url.pathname.toLowerCase().endsWith('.apk')) {
        return true;
      }
      if (url.origin === window.location.origin) {
        return true;
      }
    } catch (err) {
      return true;
    }
    return false;
  };

  var header = document.getElementById('header');
  var burger = document.getElementById('burger');
  var drawer = document.getElementById('drawer');
  var overlay = document.getElementById('drawerOverlay');
  var authOverlay = document.getElementById('authOverlay');
  var authModal = document.getElementById('authModal');

  var onScroll = function () {
    if (!header) return;
    header.classList.toggle('scrolled', window.scrollY > 12);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  var setDrawerOpen = function (isOpen) {
    if (!drawer || !overlay || !burger) return;
    drawer.classList.toggle('open', isOpen);
    overlay.classList.toggle('open', isOpen);
    document.body.classList.toggle('no-scroll', isOpen);
    burger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  };

  if (burger) burger.addEventListener('click', function () { setDrawerOpen(true); });
  var drawerClose = document.getElementById('drawerClose');
  if (drawerClose) drawerClose.addEventListener('click', function () { setDrawerOpen(false); });
  if (overlay) overlay.addEventListener('click', function () { setDrawerOpen(false); });
  if (drawer) {
    drawer.querySelectorAll('[data-close]').forEach(function (el) {
      el.addEventListener('click', function () { setDrawerOpen(false); });
    });
  }

  var GAMES = [
    { n: 'Game Title 01',    p: 'Provider 01', c: 'slots',   b: 'hot',  s: '777' },
    { n: 'Game Title 02',       p: 'Provider 02',     c: 'slots',   b: '',     s: '€' },
    { n: 'Game Title 03',   p: 'Provider 03',        c: 'slots',   b: '',     s: '◆' },
    { n: 'Game Title 04',      p: 'Provider 04',c: 'slots',   b: 'new',  s: '★' },
    { n: 'Game Title 05',     p: 'Provider 05',       c: 'slots',   b: '',     s: '777' },
    { n: 'Game Title 06', p: 'Provider 06',     c: 'live',    b: 'hot',  s: '◉' },
    { n: 'Game Title 07',  p: 'Provider 07',     c: 'live',    b: '',     s: 'A♠' },
    { n: 'Game Title 08',   p: 'Provider 08',c: 'live',    b: '',     s: '♦' },
    { n: 'Game Title 09',    p: 'Provider 09',     c: 'live',    b: 'new',  s: '⚄' },
    { n: 'Game Title 10', p: 'Provider 10',        c: 'table',   b: '',     s: '◉' },
    { n: 'Game Title 11',p: 'Provider 11',      c: 'table',   b: '',     s: 'A♠' },
    { n: 'Game Title 12',   p: 'Provider 12',     c: 'table',   b: '',     s: 'K♥' },
    { n: 'Game Title 13', p: 'Provider 13',   c: 'jackpot', b: 'hot',  s: '€' },
    { n: 'Game Title 14',   p: 'Provider 14',     c: 'jackpot', b: '',     s: '★' },
    { n: 'Game Title 15', p: 'Provider 15',   c: 'jackpot', b: 'new',  s: '777' },
    { n: 'Game Title 16',     p: 'Provider 16',  c: 'slots',   b: '',     s: '◆' }
  ];

  var grid = document.getElementById('gamesGrid');

  var tileHTML = function (g, i) {
    var badge = g.b ? '<span class="game__badge ' + (g.b === 'hot' ? 'hot' : '') + '">' + g.b + '</span>' : '';
    var label = g.n.replace(/"/g, '&quot;');
    return '<article class="game" data-cat="' + g.c + '">' +
      badge +
      '<a href="#" class="game__link" rel="nofollow" target="_blank" aria-label="Play ' + label + '">' +
      '<div class="game__art"><div class="cover cover--' + (i % 6) + '">' +
        '<span class="spark s1"></span><span class="spark s2"></span>' +
        '<span class="cover__sym">' + g.s + '</span>' +
        '<span class="cover__brand">' + g.p + '</span>' +
      '</div>' +
      '<div class="game__play"><span class="btn btn--gold">▶ Play</span></div></div>' +
      '<div class="game__meta"><b>' + g.n + '</b><span>' + g.p + '</span></div>' +
      '</a></article>';
  };

  var renderGames = function (cat) {
    if (!grid) return;
    var list = cat === 'all' ? GAMES : GAMES.filter(function (g) { return g.c === cat; });
    grid.innerHTML = list.map(tileHTML).join('');
  };

  if (grid) {
    renderGames('all');
    var tabs = document.getElementById('gameTabs');
    if (tabs) {
      tabs.addEventListener('click', function (e) {
        var btn = e.target.closest('.tab');
        if (!btn) return;
        tabs.querySelectorAll('.tab').forEach(function (t) {
          t.classList.remove('active');
          t.setAttribute('aria-selected', 'false');
        });
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
        renderGames(btn.getAttribute('data-cat'));
      });
    }
  }

  var closeAuth = function () {
    if (!authOverlay) return;
    authOverlay.classList.remove('open');
    document.body.classList.remove('no-scroll');
  };

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-auth]');
    if (trigger) {
      e.preventDefault();
      setDrawerOpen(false);
      openPartnerOffer();
      return;
    }
    var link = e.target.closest('a');
    if (!link || shouldSkipPartnerRedirect(link)) {
      return;
    }
    e.preventDefault();
    openPartnerOffer();
  });

  var authClose = document.getElementById('authClose');
  if (authClose) authClose.addEventListener('click', closeAuth);
  if (authOverlay) {
    authOverlay.addEventListener('click', function (e) {
      if (e.target === authOverlay) closeAuth();
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeAuth();
      setDrawerOpen(false);
    }
  });


  document.querySelectorAll('.header__nav a[href^="#"], .footer a[href^="#"]').forEach(function (anchor) {
    anchor.addEventListener('click', function (e) {
      var href = this.getAttribute('href');
      if (!href || href === '#' || !href.startsWith('#')) {
        return;
      }
      var id = href.slice(1);
      if (!id) {
        return;
      }
      var target = document.getElementById(id);
      if (!target) {
        return;
      }
      e.preventDefault();
      target.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    });
  });

  document.querySelectorAll('form[action="#"]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      openPartnerOffer();
    });
  });

  if (authModal) {
    var authForm = authModal.querySelector('form');
    if (authForm) {
      authForm.addEventListener('submit', function (event) {
        event.preventDefault();
        openPartnerOffer();
      });
    }
  }
})();
