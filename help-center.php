
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "components/header.php";
include "components/sidebar.php";
?>

<main class="help-page">

    <!-- Hero -->
    <section class="help-hero">
        <div class="container text-center">

            <span class="help-label">MYSHOP.IN SUPPORT</span>

            <h1>How Can We Help You?</h1>

            <p>
                Find answers to common questions about shopping,
                accounts, orders and more.
            </p>

            <div class="help-search-box">
                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    type="search"
                    id="helpSearch"
                    placeholder="Search for help..."
                    aria-label="Search help topics"
                >
            </div>

        </div>
    </section>

    <!-- Help Categories -->
    <section class="help-categories">
        <div class="container">

            <div class="text-center help-heading">
                <span class="help-subtitle">HELP & SUPPORT</span>
                <h2>Browse Help Topics</h2>
                <p>Choose a topic to find relevant information.</p>
            </div>

            <div class="row g-4">

                <div class="col-md-6 col-lg-4">
                    <a href="account.php" class="help-category-card">

                        <div class="help-category-icon">
                            <i class="fa-solid fa-user"></i>
                        </div>

                        <h4>My Account</h4>

                        <p>
                            Access your account and manage
                            your customer information.
                        </p>

                        <span>Manage Account
                            <i class="fa-solid fa-arrow-right"></i>
                        </span>

                    </a>
                </div>

                <div class="col-md-6 col-lg-4">
                    <a href="orders.php" class="help-category-card">

                        <div class="help-category-icon">
                            <i class="fa-solid fa-box"></i>
                        </div>

                        <h4>Orders & Delivery</h4>

                        <p>
                            Review your orders and find
                            order-related information.
                        </p>

                        <span>View Orders
                            <i class="fa-solid fa-arrow-right"></i>
                        </span>

                    </a>
                </div>

                <div class="col-md-6 col-lg-4">
                    <a href="returns-refunds.php" class="help-category-card">

                        <div class="help-category-icon">
                            <i class="fa-solid fa-rotate-left"></i>
                        </div>

                        <h4>Returns & Refunds</h4>

                        <p>
                            Learn how to request assistance
                            with returns and refunds.
                        </p>

                        <span>Get Return Help
                            <i class="fa-solid fa-arrow-right"></i>
                        </span>

                    </a>
                </div>

                <div class="col-md-6 col-lg-4">
                    <a href="cart.php" class="help-category-card">

                        <div class="help-category-icon">
                            <i class="fa-solid fa-cart-shopping"></i>
                        </div>

                        <h4>Shopping & Cart</h4>

                        <p>
                            Explore your shopping cart
                            and review selected products.
                        </p>

                        <span>View Cart
                            <i class="fa-solid fa-arrow-right"></i>
                        </span>

                    </a>
                </div>

                <div class="col-md-6 col-lg-4">
                    <a href="wishlist.php" class="help-category-card">

                        <div class="help-category-icon">
                            <i class="fa-solid fa-heart"></i>
                        </div>

                        <h4>Wishlist</h4>

                        <p>
                            Access products you have
                            saved to your wishlist.
                        </p>

                        <span>View Wishlist
                            <i class="fa-solid fa-arrow-right"></i>
                        </span>

                    </a>
                </div>

                <div class="col-md-6 col-lg-4">
                    <a href="contact-us.php" class="help-category-card">

                        <div class="help-category-icon">
                            <i class="fa-solid fa-headset"></i>
                        </div>

                        <h4>Contact Support</h4>

                        <p>
                            Send us your questions,
                            feedback or concerns.
                        </p>

                        <span>Contact Us
                            <i class="fa-solid fa-arrow-right"></i>
                        </span>

                    </a>
                </div>

            </div>

        </div>
    </section>

    <!-- Frequently Asked Questions -->
    <section class="help-faq">
        <div class="container">

            <div class="text-center help-heading">
                <span class="help-subtitle">QUICK ANSWERS</span>
                <h2>Frequently Asked Questions</h2>
                <p>Find answers to some common shopping questions.</p>
            </div>

            <div class="accordion help-accordion" id="helpAccordion">

                <div class="accordion-item help-faq-item">

                    <h3 class="accordion-header">
                        <button
                            class="accordion-button"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#helpOne"
                            aria-expanded="true"
                            aria-controls="helpOne">

                            How can I create a MyShop.in account?

                        </button>
                    </h3>

                    <div
                        id="helpOne"
                        class="accordion-collapse collapse show"
                        data-bs-parent="#helpAccordion">

                        <div class="accordion-body">
                            Click the Sign Up option in the website header
                            and follow the registration process to create
                            your customer account.
                        </div>

                    </div>

                </div>

                <div class="accordion-item help-faq-item">

                    <h3 class="accordion-header">
                        <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#helpTwo"
                            aria-expanded="false"
                            aria-controls="helpTwo">

                            How can I check my orders?

                        </button>
                    </h3>

                    <div
                        id="helpTwo"
                        class="accordion-collapse collapse"
                        data-bs-parent="#helpAccordion">

                        <div class="accordion-body">
                            Visit the My Orders section from your account
                            to review your available order details.
                            <a href="orders.php">View My Orders</a>.
                        </div>

                    </div>

                </div>

                <div class="accordion-item help-faq-item">

                    <h3 class="accordion-header">
                        <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#helpThree"
                            aria-expanded="false"
                            aria-controls="helpThree">

                            How can I save products for later?

                        </button>
                    </h3>

                    <div
                        id="helpThree"
                        class="accordion-collapse collapse"
                        data-bs-parent="#helpAccordion">

                        <div class="accordion-body">
                            Use the wishlist option on supported products
                            to save them and access them later from your
                            wishlist.
                            <a href="wishlist.php">View Wishlist</a>.
                        </div>

                    </div>

                </div>

                <div class="accordion-item help-faq-item">

                    <h3 class="accordion-header">
                        <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#helpFour"
                            aria-expanded="false"
                            aria-controls="helpFour">

                            How can I get help with a return or refund?

                        </button>
                    </h3>

                    <div
                        id="helpFour"
                        class="accordion-collapse collapse"
                        data-bs-parent="#helpAccordion">

                        <div class="accordion-body">
                            Visit our Returns & Refunds page for information
                            about requesting assistance with a purchase.
                            <a href="returns-refunds.php">Read Returns & Refunds</a>.
                        </div>

                    </div>

                </div>

                <div class="accordion-item help-faq-item">

                    <h3 class="accordion-header">
                        <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#helpFive"
                            aria-expanded="false"
                            aria-controls="helpFive">

                            How can I contact MyShop.in?

                        </button>
                    </h3>

                    <div
                        id="helpFive"
                        class="accordion-collapse collapse"
                        data-bs-parent="#helpAccordion">

                        <div class="accordion-body">
                            Visit our Contact Us page and submit your
                            enquiry through the contact form.
                            <a href="contact-us.php">Contact Us</a>.
                        </div>

                    </div>

                </div>

            </div>

        </div>
    </section>

    <!-- Bottom CTA -->
    <section class="help-bottom">
        <div class="container text-center">

            <div class="help-bottom-icon">
                <i class="fa-solid fa-comments"></i>
            </div>

            <h2>Can't Find What You're Looking For?</h2>

            <p>
                Send us a message and tell us how we can help.
            </p>

            <a href="contact-us.php" class="help-contact-btn">
                Get in Touch
                <i class="fa-solid fa-arrow-right ms-2"></i>
            </a>

        </div>
    </section>

</main>

<!-- Help Center Search -->
<script>
document.addEventListener("DOMContentLoaded", function () {

    const searchInput = document.getElementById("helpSearch");
    const faqItems = document.querySelectorAll(".help-faq-item");
    const categoryCards = document.querySelectorAll(".help-category-card");

    if (!searchInput) return;

    searchInput.addEventListener("input", function () {

        const searchText = this.value.trim().toLowerCase();

        faqItems.forEach(function (item) {

            const text = item.textContent.toLowerCase();

            item.style.display =
                text.includes(searchText) ? "" : "none";

        });

        categoryCards.forEach(function (card) {

            const text = card.textContent.toLowerCase();

            card.parentElement.style.display =
                text.includes(searchText) ? "" : "none";

        });

    });

});
</script>

<?php include "components/footer.php"; ?>
