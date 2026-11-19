// JavaScript de todo el sistema (se carga en todas las páginas, incluido el login).
(function () {
  'use strict';

  /* ---------- Menú lateral (solo existe en las páginas con sesión) ---------- */
  var btn = document.getElementById('menuBtn'),
      drawer = document.getElementById('drawer'),
      overlay = document.getElementById('overlay'),
      body = document.body;

  if (btn && drawer && overlay) {
    var abrir = function (v) {
      body.classList.toggle('menu-abierto', v);
      btn.setAttribute('aria-expanded', v ? 'true' : 'false');
      btn.setAttribute('aria-label', v ? 'Cerrar menú' : 'Abrir menú');
      if (v) {
        var primero = drawer.querySelector('a');
        if (primero) setTimeout(function () { primero.focus(); }, 50);
      } else {
        btn.focus();
      }
    };
    btn.addEventListener('click', function () { abrir(!body.classList.contains('menu-abierto')); });
    overlay.addEventListener('click', function () { abrir(false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && body.classList.contains('menu-abierto')) abrir(false);
    });
  }

  /* ---------- Ojito para mostrar/ocultar contraseñas ---------- */
  var svg = function (contenido) {
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" ' +
           'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + contenido + '</svg>';
  };
  var OJO = svg('<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>');
  var OJO_TACHADO = svg('<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>');

  document.querySelectorAll('input[type="password"]').forEach(function (inp) {
    var wrap = document.createElement('span');
    wrap.className = 'pw-wrap';
    inp.parentNode.insertBefore(wrap, inp);
    wrap.appendChild(inp);

    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'pw-toggle';
    b.innerHTML = OJO;
    b.setAttribute('aria-label', 'Mostrar contraseña');
    b.setAttribute('aria-pressed', 'false');
    b.addEventListener('click', function () {
      var ver = inp.type === 'password';
      inp.type = ver ? 'text' : 'password';
      b.innerHTML = ver ? OJO_TACHADO : OJO;
      b.setAttribute('aria-label', ver ? 'Ocultar contraseña' : 'Mostrar contraseña');
      b.setAttribute('aria-pressed', ver ? 'true' : 'false');
      inp.focus();
    });
    wrap.appendChild(b);

    // Al enviar el formulario la contraseña vuelve a ocultarse
    if (inp.form) inp.form.addEventListener('submit', function () { inp.type = 'password'; });
  });

  /* ---------- Sin historial de autocompletado en los campos de captura ----------
     Se apaga en cada campo de los formularios (excepto los que llevan contraseña: login y cambio de
     contraseña, para no estorbar a los gestores de contraseñas). */
  document.querySelectorAll('form').forEach(function (f) {
    if (f.querySelector('input[type="password"]')) return;
    f.setAttribute('autocomplete', 'off');
    f.querySelectorAll('input, select, textarea').forEach(function (c) {
      if (c.type !== 'hidden') c.setAttribute('autocomplete', 'off');
    });
  });

  /* ---------- Atributos data-* (reemplazan a los onclick/onchange/onsubmit en línea) ---------- */
  // <form data-confirm="¿Seguro?"> pide confirmación antes de enviar
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });
  // <select data-autosubmit> envía el formulario al cambiar; data-limpiar="campo1,campo2" vacía antes esos campos
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el.hasAttribute || !el.hasAttribute('data-autosubmit') || !el.form) return;
    var limpiar = el.getAttribute('data-limpiar');
    if (limpiar) limpiar.split(',').forEach(function (n) {
      var c = el.form.elements[n.trim()];
      if (c) c.value = '';
    });
    el.form.submit();
  });
  // <button data-imprimir> abre el diálogo de impresión; <tr data-href="..."> es una fila que lleva a otra página
  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    if (e.target.closest('[data-imprimir]')) { window.print(); return; }
    var fila = e.target.closest('tr[data-href]');
    if (fila && !e.target.closest('a, button, input, select')) location.href = fila.getAttribute('data-href');
  });

  // Si el navegador restaura la página con «Atrás», se recarga para que el servidor revise la sesión.
  window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
})();
