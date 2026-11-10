
(function () {
    'use strict';

    var content = document.getElementById('adminContent');
    if (!content) { return; }

    var progress = document.getElementById('adminProgress');
    var baseDir = location.pathname.substring(0, location.pathname.lastIndexOf('/') + 1);
    var controller = null;
    var loadedScripts = new Set();
    var progressTimer = null;
    Array.prototype.forEach.call(document.scripts, function (s) {
        if (s.src) { loadedScripts.add(s.src); }
    });
    var Admin = window.Admin || { _q: [] };
    var readyQueue = Admin._q || [];

    Admin.onReady = function (fn) { readyQueue.push(fn); };
    Admin.navigate = function (url) { return load(new URL(url, location.href).href); };
    Admin.reload = function () { return load(location.href, { push: false }); };
    window.Admin = Admin;

    function runReady() {
        var fns = readyQueue;
        readyQueue = [];
        fns.forEach(function (fn) {
            try { fn(); } catch (err) { console.error('Admin.onReady error:', err); }
        });
    }
    function csrfToken() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function isAdminUrl(url) {
        return url.origin === location.origin &&
            url.pathname.indexOf(baseDir) === 0 &&
            /\.php$/i.test(url.pathname);
    }

    function startProgress() {
        clearTimeout(progressTimer);
        if (progress) {
            progress.style.transition = 'none';
            progress.style.width = '0';
            progress.style.opacity = '1';
            void progress.offsetWidth;
            progress.style.transition = '';
            progress.style.width = '70%';
        }
        progressTimer = setTimeout(function () { content.classList.add('is-loading'); }, 150);
    }

    function stopProgress() {
        clearTimeout(progressTimer);
        content.classList.remove('is-loading');
        if (progress) {
            progress.style.width = '100%';
            setTimeout(function () {
                progress.style.opacity = '0';
                progress.style.width = '0';
            }, 250);
        }
    }

    function setActiveMenu(key, url) {
        var links = document.querySelectorAll('#adminSidebar [data-menu]');
        var file = (url || location.pathname).split('?')[0].split('/').pop();

        links.forEach(function (a) {
            var match = key
                ? a.getAttribute('data-menu') === key
                : (a.getAttribute('href') || '').split('?')[0] === file;

            a.classList.toggle('active', match);
            if (match) { a.setAttribute('aria-current', 'page'); }
            else { a.removeAttribute('aria-current'); }
        });
    }

    function showError(message, retry) {
        var old = content.querySelector('.admin-ajax-error');
        if (old) { old.remove(); }

        var box = document.createElement('div');
        box.className = 'admin-ajax-error alert alert-danger d-flex justify-content-between align-items-center';
        box.innerHTML = '<span></span><button type="button" class="btn btn-sm btn-outline-danger">Retry</button>';
        box.firstChild.textContent = message;
        box.querySelector('button').addEventListener('click', function () { box.remove(); retry(); });
        content.prepend(box);
    }

    function cleanupBeforeSwap() {
        if (window.bootstrap) {
            content.querySelectorAll('.modal').forEach(function (m) {
                var inst = bootstrap.Modal.getInstance(m);
                if (inst) { inst.dispose(); }
            });
            content.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                var t = bootstrap.Tooltip.getInstance(el);
                if (t) { t.dispose(); }
            });
        }
        document.querySelectorAll('.modal-backdrop, .tooltip, .popover').forEach(function (n) { n.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }
    function runScripts(root) {
        var waits = [];

        root.querySelectorAll('script').forEach(function (old) {
            var s = document.createElement('script');
            Array.prototype.forEach.call(old.attributes, function (a) { s.setAttribute(a.name, a.value); });

            if (old.src) {
                if (loadedScripts.has(old.src)) { old.remove(); return; }
                loadedScripts.add(old.src);
                s.async = false; // keep order
                waits.push(new Promise(function (res) { s.onload = s.onerror = res; }));
            } else {
                s.textContent = old.textContent;
            }
            old.replaceWith(s);
        });

        return Promise.all(waits);
    }
    function ensureCsrf(root) {
        var token = csrfToken();
        if (!token) { return; }

        root.querySelectorAll('form').forEach(function (f) {
            if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
            if (f.querySelector('input[name="csrf_token"]')) { return; }
            var i = document.createElement('input');
            i.type = 'hidden';
            i.name = 'csrf_token';
            i.value = token;
            f.appendChild(i);
        });
    }

    function initWidgets() {
        ensureCsrf(content);
        if (window.bootstrap) {
            content.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                new bootstrap.Tooltip(el);
            });
        }
        document.body.classList.remove('sidebar-open');
    }
    async function load(url, opts) {
        opts = opts || {};
        var push = opts.push !== false;
        var method = opts.method || 'GET';

        if (controller) { controller.abort(); }
        controller = new AbortController();
        startProgress();

        try {
            var headers = { 'X-Requested-With': 'XMLHttpRequest' };
            if (method !== 'GET') { headers['X-CSRF-Token'] = csrfToken(); }

            var res = await fetch(url, {
                method: method,
                body: opts.body || null,
                headers: headers,
                credentials: 'same-origin',
                signal: controller.signal
            });

            var finalUrl = new URL(res.url, location.href);
            if (res.status === 401) {
                window.location.href = method === 'GET' ? url : location.href;
                return false;
            }
            if (!isAdminUrl(finalUrl)) {
                window.location.href = finalUrl.href;
                return false;
            }

            var html = await res.text();
            if (res.headers.get('X-Admin-Fragment') !== '1') {
                if (method === 'GET') {
                    window.location.href = finalUrl.href;
                } else {
                    document.open();
                    document.write(html);
                    document.close();
                }
                return false;
            }

            cleanupBeforeSwap();
            readyQueue = [];
            content.innerHTML = html;

            var title = res.headers.get('X-Page-Title');
            if (title) { document.title = decodeURIComponent(title); }

            if (push) {
                if (finalUrl.href === location.href) { history.replaceState({ admin: 1 }, '', finalUrl.href); }
                else { history.pushState({ admin: 1 }, '', finalUrl.href); }
            }

            setActiveMenu(res.headers.get('X-Active-Menu'), finalUrl.pathname);
            window.scrollTo(0, 0);

            await runScripts(content);
            initWidgets();
            runReady();

            document.dispatchEvent(new CustomEvent('admin:page-load', { detail: { url: finalUrl.href } }));
            return true;

        } catch (err) {
            if (err && err.name === 'AbortError') { return false; }
            console.error('Admin load failed:', err);
            showError('Network error. Please check your connection and try again.', function () { load(url, opts); });
            return false;
        } finally {
            stopProgress();
        }
    }
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }

        var a = e.target.closest('a[href]');
        if (!a) { return; }
        if (a.hasAttribute('data-no-ajax') || a.hasAttribute('download')) { return; }
        if (a.target && a.target !== '_self') { return; }

        var raw = a.getAttribute('href');
        if (!raw || raw.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(raw)) { return; }

        var url = new URL(a.href, location.href);
        if (!isAdminUrl(url)) { return; }
        if (url.pathname === location.pathname && url.search === location.search && url.hash) { return; }

        e.preventDefault();
        load(url.href);
    });
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || !(form instanceof HTMLFormElement)) { return; }
        if (!content.contains(form) || form.hasAttribute('data-no-ajax')) { return; }

        var submitter = e.submitter || null;
        var actionAttr = (submitter && submitter.getAttribute('formaction')) || form.getAttribute('action') || location.href;
        var url = new URL(actionAttr, location.href);
        if (!isAdminUrl(url)) { return; }

        var method = ((submitter && submitter.getAttribute('formmethod')) || form.getAttribute('method') || 'get').toUpperCase();

        e.preventDefault();

        var data;
        try { data = new FormData(form, submitter || undefined); }
        catch (err) {
            data = new FormData(form);
            if (submitter && submitter.name) { data.append(submitter.name, submitter.value); }
        }

        if (method === 'GET') {
            var params = new URLSearchParams();
            data.forEach(function (v, k) { if (typeof v === 'string') { params.append(k, v); } });
            url.search = params.toString();
            load(url.href);
            return;
        }

        if (!data.has('csrf_token') && csrfToken()) { data.append('csrf_token', csrfToken()); }

        if (submitter) { submitter.disabled = true; }
        load(url.href, { method: 'POST', body: data }).then(function (ok) {
            if (!ok && submitter && document.contains(submitter)) { submitter.disabled = false; }
        });
    });
    var lastPath = location.pathname + location.search;

    window.addEventListener('popstate', function () {
        var now = location.pathname + location.search;
        if (now === lastPath) { return; } 
        load(location.href, { push: false });
    });

    document.addEventListener('admin:page-load', function () { lastPath = location.pathname + location.search; });
    var toggle = document.getElementById('sidebarToggle');
    var overlay = document.getElementById('adminOverlay');
    if (toggle) { toggle.addEventListener('click', function () { document.body.classList.toggle('sidebar-open'); }); }
    if (overlay) { overlay.addEventListener('click', function () { document.body.classList.remove('sidebar-open'); }); }
    history.replaceState({ admin: 1 }, '', location.href);
    initWidgets();
    runReady();
})();
