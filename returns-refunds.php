
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "components/header.php";
include "components/sidebar.php";
?>

<main class="returns-page">

    <!-- Hero Section -->
    <section class="returns-hero">
        <div class="container text-center">

            <span class="returns-label">CUSTOMER SUPPORT</span>

            <h1>Returns & Refunds</h1>

            <p>
                Need help with a purchase? Find out how to contact us
                about a return, refund or order-related concern.
            </p>

        </div>
    </section>

    <!-- Introduction -->
    <section class="returns-intro">
        <div class="container">

            <div class="returns-intro-content">

                <div class="returns-main-icon">
                    <i class="fa-solid fa-box-open"></i>
                </div>

                <span class="returns-subtitle">SHOP WITH CONFIDENCE</span>

                <h2>We're Here to Help</h2>

                <p>
                    At MyShop.in, we want you to have a smooth shopping
                    experience. If you have a concern about a product
                    or an order, our support team is here to help you
                    understand the available options.
                </p>

                <p>
                    Return and refund requests are reviewed according
                    to the applicable policy for the purchased product
                    and order.
                </p>

            </div>

        </div>
    </section>

    <!-- Process -->
    <section class="returns-process">
        <div class="container">

            <div class="text-center returns-section-heading">

                <span class="returns-subtitle">HOW TO GET HELP</span>

                <h2>Return & Refund Assistance</h2>

                <p>
                    Follow these steps to contact us about an order concern.
                </p>

            </div>

            <div class="row g-4">

                <div class="col-md-4">

                    <div class="returns-step-card">

                        <div class="returns-step-number">01</div>

                        <div class="returns-step-icon">
                            <i class="fa-solid fa-receipt"></i>
                        </div>

                        <h4>Check Your Order</h4>

                        <p>
                            Review your order details and identify
                            the product or issue you need assistance with.
                        </p>

                        <a href="orders.php">
                            View My Orders
                            <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="returns-step-card">

                        <div class="returns-step-number">02</div>

                        <div class="returns-step-icon">
                            <i class="fa-solid fa-envelope"></i>
                        </div>

                        <h4>Contact Our Team</h4>

                        <p>
                            Send us your order-related concern with
                            the relevant details so we can review it.
                        </p>

                        <a href="contact-us.php">
                            Contact Us
                            <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="returns-step-card">

                        <div class="returns-step-number">03</div>

                        <div class="returns-step-icon">
                            <i class="fa-solid fa-clipboard-check"></i>
                        </div>

                        <h4>Request Review</h4>

                        <p>
                            Our team can review your request and
                            communicate the applicable next steps.
                        </p>

                    </div>

                </div>

            </div>

        </div>
    </section>

    <!-- Important Information -->
    <section class="returns-information">
        <div class="container">

            <div class="row align-items-center g-5">

                <div class="col-lg-6">

                    <span class="returns-subtitle">
                        IMPORTANT INFORMATION
                    </span>

                    <h2>Before Requesting a Return or Refund</h2>

                    <p>
                        To help us understand your concern, please
                        keep the following information ready:
                    </p>

                    <ul class="returns-check-list">

                        <li>
                            <i class="fa-solid fa-check-circle"></i>
                            Your order details or order number.
                        </li>

                        <li>
                            <i class="fa-solid fa-check-circle"></i>
                            The name of the product concerned.
                        </li>

                        <li>
                            <i class="fa-solid fa-check-circle"></i>
                            A clear explanation of the issue.
                        </li>

                        <li>
                            <i class="fa-solid fa-check-circle"></i>
                            Relevant photographs, if they help explain
                            the concern.
                        </li>

                    </ul>

                </div>

                <div class="col-lg-6">

                    <div class="returns-note-card">

                        <div class="returns-note-icon">
                            <i class="fa-solid fa-circle-info"></i>
                        </div>

                        <h4>Policy Information</h4>

                        <p>
                            Return eligibility, available remedies
                            and refund processing depend on the
                            applicable product and order policy.
                        </p>

                        <p>
                            Please contact our support team for
                            clarification before sending a product back.
                        </p>

                        <a href="contact-us.php">
                            Get Assistance
                            <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>

                    </div>

                </div>

            </div>

        </div>
    </section>

    <!-- FAQ -->
    <section class="returns-faq">
        <div class="container">

            <div class="text-center returns-section-heading">

                <span class="returns-subtitle">
                    COMMON QUESTIONS
                </span>

                <h2>Returns & Refunds FAQs</h2>

                <p>
                    Some helpful information about order-related concerns.
                </p>

            </div>

            <div class="accordion returns-accordion" id="returnsAccordion">

                <div class="accordion-item">

                    <h3 class="accordion-header">

                        <button
                            class="accordion-button"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#returnQuestionOne"
                            aria-expanded="true"
                            aria-controls="returnQuestionOne">

                            How can I request a return?

                        </button>

                    </h3>

                    <div
                        id="returnQuestionOne"
                        class="accordion-collapse collapse show"
                        data-bs-parent="#returnsAccordion">

                        <div class="accordion-body">

                            Visit your orders page and note the relevant
                            order details. Then contact our support team
                            to discuss your request and the applicable
                            product policy.

                        </div>

                    </div>

                </div>

                <div class="accordion-item">

                    <h3 class="accordion-header">

                        <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#returnQuestionTwo"
                            aria-expanded="false"
                            aria-controls="returnQuestionTwo">

                            How can I ask about a refund?

                        </button>

                    </h3>

                    <div
                        id="returnQuestionTwo"
                        class="accordion-collapse collapse"
                        data-bs-parent="#returnsAccordion">

                        <div class="accordion-body">

                            Contact us with your order details and
                            explain your concern. Our team can review
                            the request and provide information about
                            the applicable refund process.

                        </div>

                    </div>

                </div>

                <div class="accordion-item">

                    <h3 class="accordion-header">

                        <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#returnQuestionThree"
                            aria-expanded="false"
                            aria-controls="returnQuestionThree">

                            What should I do if I receive a damaged product?

                        </button>

                    </h3>

                    <div
                        id="returnQuestionThree"
                        class="accordion-collapse collapse"
                        data-bs-parent="#returnsAccordion">

                        <div class="accordion-body">

                            Contact our support team and describe the
                            issue. You can also provide photographs
                            that help explain the condition of the
                            product you received.

                        </div>

                    </div>

                </div>

                <div class="accordion-item">

                    <h3 class="accordion-header">

                        <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#returnQuestionFour"
                            aria-expanded="false"
                            aria-controls="returnQuestionFour">

                            How can I check my order details?

                        </button>

                    </h3>

                    <div
                        id="returnQuestionFour"
                        class="accordion-collapse collapse"
                        data-bs-parent="#returnsAccordion">

                        <div class="accordion-body">

                            Visit the My Orders section of your
                            account to review your available order
                            information.

                            <a href="orders.php">View My Orders</a>

                        </div>

                    </div>

                </div>

            </div>

        </div>
    </section>

    <!-- Contact CTA -->
    <section class="returns-contact">

        <div class="container text-center">

            <div class="returns-contact-icon">
                <i class="fa-solid fa-headset"></i>
            </div>

            <h2>Still Need Help?</h2>

            <p>
                Our contact page is available for your questions,
                feedback and order-related concerns.
            </p>

            <a href="contact-us.php" class="returns-contact-btn">
                Contact Support
                <i class="fa-solid fa-arrow-right ms-2"></i>
            </a>

        </div>

    </section>

</main>

<?php include "components/footer.php"; ?>
