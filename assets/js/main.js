(() => {
  'use strict';

  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

  // ---------- Rok w stopce ----------
  const year = $('#year');
  if (year) year.textContent = new Date().getFullYear();

  // ---------- Header: cień po przewinięciu ----------
  const header = $('.header');
  const onScroll = () => header && header.classList.toggle('is-scrolled', window.scrollY > 8);
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  // ---------- Menu mobilne ----------
  const toggle = $('.nav-toggle');
  const nav = $('#nav');
  const setMenu = (open) => {
    document.body.classList.toggle('nav-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Zamknij menu' : 'Otwórz menu');
  };
  if (toggle && nav) {
    toggle.addEventListener('click', () => setMenu(!document.body.classList.contains('nav-open')));
    $$('a', nav).forEach((a) => a.addEventListener('click', () => setMenu(false)));
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && document.body.classList.contains('nav-open')) { setMenu(false); toggle.focus(); }
    });
    window.matchMedia('(min-width: 1025px)').addEventListener('change', (e) => e.matches && setMenu(false));
  }

  // ---------- Aktywna pozycja menu ----------
  const links = $$('.nav__list a[href^="#"]');
  const sections = links.map((a) => $(a.getAttribute('href'))).filter(Boolean);
  if ('IntersectionObserver' in window && sections.length) {
    const spy = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        links.forEach((a) => a.classList.toggle('is-active', a.getAttribute('href') === '#' + entry.target.id));
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    sections.forEach((s) => spy.observe(s));
  }

  // ---------- Animacje wejścia ----------
  const reveals = $$('.reveal');
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if ('IntersectionObserver' in window && !reduced) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        io.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    // lekkie opóźnienie dla elementów w tej samej siatce
    reveals.forEach((el) => {
      const siblings = $$(':scope > .reveal', el.parentElement);
      const i = siblings.indexOf(el);
      if (i > 0) el.style.transitionDelay = Math.min(i * 70, 350) + 'ms';
      io.observe(el);
    });
  } else {
    reveals.forEach((el) => el.classList.add('is-visible'));
  }

  // ---------- Galeria: pokaż więcej ----------
  const moreBtn = $('#gallery-more');
  if (moreBtn) {
    moreBtn.addEventListener('click', () => {
      const hidden = $$('.gallery__item[hidden]');
      hidden.forEach((el) => el.removeAttribute('hidden'));
      moreBtn.parentElement.remove();
      if (hidden[0]) hidden[0].focus();
    });
  }

  // ---------- Lightbox ----------
  const lb = $('#lightbox');
  const lbImg = $('#lightbox-img');
  const lbCap = $('#lightbox-caption');
  let current = 0;
  const items = () => $$('.gallery__item:not([hidden])');

  const show = (i) => {
    const list = items();
    current = (i + list.length) % list.length;
    const a = list[current];
    lbImg.src = a.getAttribute('href');
    lbImg.alt = a.dataset.caption || '';
    lbCap.textContent = a.dataset.caption || '';
  };

  if (lb && typeof lb.showModal === 'function') {
    document.addEventListener('click', (e) => {
      const a = e.target.closest('.gallery__item');
      if (!a) return;
      e.preventDefault();
      show(items().indexOf(a));
      lb.showModal();
    });
    lb.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-lb]');
      if (btn) {
        const act = btn.dataset.lb;
        if (act === 'close') lb.close();
        if (act === 'prev') show(current - 1);
        if (act === 'next') show(current + 1);
      } else if (e.target === lb || e.target.classList.contains('lightbox__stage')) {
        lb.close();
      }
    });
    lb.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft') show(current - 1);
      if (e.key === 'ArrowRight') show(current + 1);
    });
    // gest przesunięcia na telefonie
    let x0 = null;
    lb.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; }, { passive: true });
    lb.addEventListener('touchend', (e) => {
      if (x0 === null) return;
      const dx = e.changedTouches[0].clientX - x0;
      if (Math.abs(dx) > 50) show(current + (dx < 0 ? 1 : -1));
      x0 = null;
    });
    lb.addEventListener('close', () => { lbImg.src = ''; });
  }

  // ---------- Formularz kontaktowy ----------
  const form = $('#contact-form');
  if (!form) return;

  const ts = $('#f-ts');
  if (ts) ts.value = String(Date.now());

  const status = $('#form-status');
  const messages = {
    valueMissing: 'To pole jest wymagane.',
    typeMismatch: 'Podaj poprawny adres e-mail.',
    consent: 'Zaznacz zgodę, abyśmy mogli odpowiedzieć na zapytanie.'
  };

  const fieldError = (input) => {
    const box = input.closest('.field');
    return box ? $('.field__error', box) : null;
  };

  const validate = (input) => {
    const err = fieldError(input);
    let msg = '';
    if (!input.validity.valid) {
      if (input.type === 'checkbox') msg = messages.consent;
      else if (input.validity.typeMismatch) msg = messages.typeMismatch;
      else msg = messages.valueMissing;
    }
    input.setAttribute('aria-invalid', msg ? 'true' : 'false');
    if (err) err.textContent = msg;
    return !msg;
  };

  const required = $$('[required]', form);
  required.forEach((input) => {
    input.addEventListener('blur', () => { if (input.value || input.type === 'checkbox') validate(input); });
    input.addEventListener('input', () => { if (input.getAttribute('aria-invalid') === 'true') validate(input); });
    input.addEventListener('change', () => { if (input.type === 'checkbox') validate(input); });
  });

  const setStatus = (type, text) => {
    status.className = 'form-status is-' + type;
    status.textContent = text;
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const results = required.map(validate);
    if (results.includes(false)) {
      const first = required[results.indexOf(false)];
      first.focus();
      return;
    }

    // podgląd na GitHub Pages nie obsługuje PHP — formularz zadziała dopiero na docelowym hostingu
    if (location.hostname.endsWith('github.io')) {
      setStatus('success', 'Wersja testowa strony — formularz zacznie wysyłać wiadomości po publikacji na docelowym serwerze.');
      return;
    }

    const btn = $('button[type="submit"]', form);
    btn.setAttribute('aria-busy', 'true');
    status.className = 'form-status';

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' }
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.error || 'send');
      form.reset();
      ts.value = String(Date.now());
      setStatus('success', 'Dziękujemy! Zapytanie zostało wysłane — odezwiemy się najszybciej, jak to możliwe.');
    } catch (err) {
      setStatus('error', 'Nie udało się wysłać wiadomości. Zadzwoń: 602 628 145 lub napisz na tomko@kotlytomko.pl.');
    } finally {
      btn.removeAttribute('aria-busy');
    }
  });

  // komunikat po wysłaniu bez JS (przekierowanie z kontakt.php)
  const params = new URLSearchParams(location.search);
  if (params.has('wyslano')) {
    setStatus(params.get('wyslano') === '1' ? 'success' : 'error',
      params.get('wyslano') === '1'
        ? 'Dziękujemy! Zapytanie zostało wysłane.'
        : 'Nie udało się wysłać wiadomości. Zadzwoń: 602 628 145.');
  }
})();
