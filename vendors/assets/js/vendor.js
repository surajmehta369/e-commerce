
(function () {
    "use strict";

    const Vendor = {
        content: null,
        sidebar: null,
        overlay: null,
        progress: null,
        toggle: null,

        init() {
            this.content = document.getElementById("vendorContent");
            this.sidebar = document.getElementById("vendorSidebar");
            this.overlay = document.getElementById("vendorOverlay");
            this.progress = document.getElementById("vendorProgress");
            this.toggle = document.getElementById("vendorSidebarToggle");

            this.bindEvents();
        },

        bindEvents() {

            document.addEventListener("click", (event) => {

                const link = event.target.closest("a");

                if (!link) {
                    return;
                }

                if (
                    link.target === "_blank" ||
                    link.hasAttribute("download") ||
                    link.hasAttribute("data-no-ajax") ||
                    event.ctrlKey ||
                    event.metaKey ||
                    event.shiftKey ||
                    event.altKey
                ) {
                    return;
                }

                const url = new URL(link.href, window.location.href);

                if (
                    url.origin !== window.location.origin ||
                    !url.pathname.endsWith(".php") ||
                    url.pathname.includes("/outh/")
                ) {
                    return;
                }

                event.preventDefault();

                this.navigate(url.href);
            });

            document.addEventListener("submit", (event) => {

                const form = event.target;

                if (
                    !(form instanceof HTMLFormElement) ||
                    form.hasAttribute("data-no-ajax")
                ) {
                    return;
                }

                const method = (form.method || "GET").toUpperCase();

                if (!["GET", "POST"].includes(method)) {
                    return;
                }

                const action = new URL(
                    form.action || window.location.href,
                    window.location.href
                );

                if (
                    action.origin !== window.location.origin ||
                    !action.pathname.endsWith(".php") ||
                    action.pathname.includes("/outh/")
                ) {
                    return;
                }

                event.preventDefault();

                this.submitForm(form);
            });

            if (this.toggle) {
                this.toggle.addEventListener("click", () => {
                    this.toggleSidebar();
                });
            }

            if (this.overlay) {
                this.overlay.addEventListener("click", () => {
                    this.closeSidebar();
                });
            }

            window.addEventListener("popstate", () => {
                this.navigate(window.location.href, false);
            });
        },

        async navigate(url, addHistory = true) {

            this.setLoading(true);

            try {

                const response = await fetch(url, {
                    method: "GET",
                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                        "X-Vendor-Fragment": "1",
                        "Accept": "text/html"
                    },
                    credentials: "same-origin"
                });

                const html = await response.text();

                if (!response.ok) {
                    throw new Error("Unable to load this page.");
                }

                const isFragment =
                    response.headers.get("X-Vendor-Fragment") === "1";

                if (!isFragment) {
                    window.location.href = response.url || url;
                    return;
                }

                this.replaceContent(
                    html,
                    response.headers.get("X-Active-Menu"),
                    response.headers.get("X-Page-Title")
                );

                if (addHistory) {
                    window.history.pushState({}, "", url);
                }

                this.closeSidebar();

            } catch (error) {

                console.error("Vendor navigation error:", error);

                window.location.href = url;

            } finally {

                this.setLoading(false);
            }
        },

        async submitForm(form) {

            const method = (form.method || "GET").toUpperCase();

            const action = new URL(
                form.action || window.location.href,
                window.location.href
            );

            this.setLoading(true);

            try {

                let requestUrl = action.href;

                const options = {
                    method: method,
                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                        "X-Vendor-Fragment": "1",
                        "Accept": "text/html"
                    },
                    credentials: "same-origin"
                };

                if (method === "GET") {

                    const formData = new FormData(form);
                    const params = new URLSearchParams(formData);

                    requestUrl +=
                        (requestUrl.includes("?") ? "&" : "?") +
                        params.toString();

                } else {

                    const formData = new FormData(form);

                    const csrfToken = document.querySelector(
                        'meta[name="vendor-csrf-token"]'
                    )?.content;

                    if (
                        csrfToken &&
                        !formData.has("csrf_token")
                    ) {
                        formData.append("csrf_token", csrfToken);
                    }

                    options.body = formData;
                }

                const response = await fetch(requestUrl, options);

                const html = await response.text();

                if (!response.ok) {
                    throw new Error("Form submission failed.");
                }

                const isFragment =
                    response.headers.get("X-Vendor-Fragment") === "1";

                if (!isFragment) {
                    window.location.href = response.url || requestUrl;
                    return;
                }

                this.replaceContent(html);

                window.history.pushState(
                    {},
                    "",
                    response.url || requestUrl
                );

            } catch (error) {

                console.error("Vendor form error:", error);

                alert(
                    "Something went wrong. Please try again."
                );

            } finally {

                this.setLoading(false);
            }
        },
        replaceContent(html, activeMenu = null, encodedTitle = null) {

            const parser = new DOMParser();

            const documentObject = parser.parseFromString(
                html,
                "text/html"
            );

            const newContent = documentObject.getElementById(
                "vendorContent"
            );

            if (!newContent || !this.content) {
                window.location.reload();
                return;
            }

            this.content.innerHTML = newContent.innerHTML;

            if (encodedTitle) {
                const title = decodeURIComponent(encodedTitle);
                document.title = title + " | Vendor Panel";

                const heading = document.querySelector(
                    ".vendor-topbar-title"
                );

                if (heading) {
                    heading.textContent = title;
                }
            }

            if (activeMenu) {
                this.setActiveMenu(activeMenu);
            }

            this.executeScripts(this.content);

            document.dispatchEvent(
                new CustomEvent("vendor:page-loaded")
            );

            window.scrollTo({
                top: 0,
                behavior: "instant"
            });
        },

        getHeaderValue(html, headerName) {

            return null;
        },

        executeScripts(container) {

            const scripts = Array.from(
                container.querySelectorAll("script")
            );

            scripts.forEach((oldScript) => {

                const newScript = document.createElement("script");

                Array.from(oldScript.attributes).forEach((attribute) => {
                    newScript.setAttribute(
                        attribute.name,
                        attribute.value
                    );
                });

                newScript.textContent = oldScript.textContent;

                oldScript.replaceWith(newScript);
            });
        },

        setActiveMenu(menu) {

            document.querySelectorAll(
                "#vendorSidebar .vendor-nav-link[data-menu]"
            ).forEach((link) => {

                link.classList.toggle(
                    "active",
                    link.dataset.menu === menu
                );

                if (link.dataset.menu === menu) {
                    link.setAttribute("aria-current", "page");
                } else {
                    link.removeAttribute("aria-current");
                }
            });
        },

        setLoading(loading) {

            if (!this.progress) {
                this.progress = document.getElementById(
                    "vendorProgress"
                );
            }

            if (this.progress) {
                this.progress.classList.toggle(
                    "active",
                    loading
                );
            }
        },

        toggleSidebar() {

            if (!this.sidebar) {
                return;
            }

            this.sidebar.classList.toggle("show");

            if (this.overlay) {
                this.overlay.classList.toggle(
                    "show",
                    this.sidebar.classList.contains("show")
                );
            }
        },

        closeSidebar() {

            if (this.sidebar) {
                this.sidebar.classList.remove("show");
            }

            if (this.overlay) {
                this.overlay.classList.remove("show");
            }
        },

        onReady(callback) {

            if (typeof callback === "function") {
                callback();
            }
        }
    };

    window.Vendor = Vendor;

    document.addEventListener("DOMContentLoaded", function () {
        Vendor.init();
    });

})();
