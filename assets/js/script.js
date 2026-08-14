document.addEventListener("DOMContentLoaded", function () {

    const allBtn = document.getElementById("allMenuBtn");
    const sidebar = document.getElementById("sidebar");
    const overlay = document.getElementById("sidebarOverlay");
    const closeBtn = document.getElementById("closeSidebar");

    // Open sidebar
    if (allBtn) {
        allBtn.addEventListener("click", function (e) {
            e.preventDefault();

            sidebar.classList.add("open");
            overlay.classList.add("show");
        });
    }

    // Close sidebar
    if (closeBtn) {
        closeBtn.addEventListener("click", function () {
            sidebar.classList.remove("open");
            overlay.classList.remove("show");
        });
    }

    // Close when clicking outside
    if (overlay) {
        overlay.addEventListener("click", function () {
            sidebar.classList.remove("open");
            overlay.classList.remove("show");
        });
    }

});