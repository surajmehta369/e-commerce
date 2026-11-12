
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "components/header.php";
include "components/sidebar.php";
?>

<main class="about-page">
    <section class="about-hero">
        <div class="container">
            <div class="about-hero-content">
                <span class="about-label">WELCOME TO MYSHOP.IN</span>
                <h1>About Us</h1>
                <p>
                    Your shopping destination for discovering products
                    and enjoying a convenient online shopping experience.
                </p>
            </div>
        </div>
    </section>
    <section class="about-section">
        <div class="container">
            <div class="row align-items-center g-5">

                <div class="col-lg-6">
                    <div class="about-image-box">
                        <i class="fa-solid fa-bag-shopping"></i>
                        <h3>MyShop.in</h3>
                        <p>Your Shopping Destination</p>
                    </div>
                </div>

                <div class="col-lg-6">
                    <span class="about-subtitle">WHO WE ARE</span>

                    <h2>Making Online Shopping Simple</h2>

                    <p>
                        Welcome to MyShop.in, an online shopping platform
                        designed to make discovering and shopping for
                        products simple and convenient.
                    </p>

                    <p>
                        We aim to provide customers with an easy-to-use
                        shopping experience where they can explore
                        products, compare options and place orders
                        conveniently.
                    </p>

                    <p>
                        Whether you are browsing for something new or
                        looking for an everyday essential, MyShop.in
                        brings your shopping experience together in
                        one place.
                    </p>
                </div>

            </div>
        </div>
    </section>
    <section class="about-story" id="our-story">
        <div class="container">
            <div class="about-story-content">

                <span class="about-subtitle">OUR STORY</span>

                <h2>Our Journey</h2>

                <p>
                    MyShop.in is built around a simple idea:
                    online shopping should be straightforward,
                    accessible and convenient.
                </p>

                <p>
                    We are working towards creating a platform
                    where customers can discover products and
                    sellers can connect with customers through
                    an online marketplace.
                </p>

            </div>
        </div>
    </section>
    <section class="about-values">
        <div class="container">

            <div class="text-center mb-5">
                <span class="about-subtitle">WHAT MATTERS TO US</span>
                <h2>Our Values</h2>
                <p>What guides the MyShop.in shopping experience.</p>
            </div>

            <div class="row g-4">

                <div class="col-md-4">
                    <div class="about-value-card">
                        <div class="about-icon">
                            <i class="fa-solid fa-users"></i>
                        </div>

                        <h4>Customer Experience</h4>

                        <p>
                            We aim to make browsing and shopping
                            simple and convenient for our customers.
                        </p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="about-value-card">
                        <div class="about-icon">
                            <i class="fa-solid fa-store"></i>
                        </div>

                        <h4>Seller Opportunities</h4>

                        <p>
                            We aim to provide sellers with an online
                            platform to showcase their products.
                        </p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="about-value-card">
                        <div class="about-icon">
                            <i class="fa-solid fa-heart"></i>
                        </div>

                        <h4>Continuous Improvement</h4>

                        <p>
                            We believe in improving the platform
                            to create a better shopping experience.
                        </p>
                    </div>
                </div>

            </div>
        </div>
    </section>
    <section class="about-contact">
        <div class="container text-center">
            <h2>We're Here to Help</h2>

            <p>
                Have a question or need assistance?
                We'd be happy to hear from you.
            </p>

            <a href="contact-us.php" class="about-contact-btn">
                Contact Us
                <i class="fa-solid fa-arrow-right ms-2"></i>
            </a>
        </div>
    </section>

</main>

<?php include "components/footer.php"; ?>
