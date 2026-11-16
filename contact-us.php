<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$successMessage = "";
$errorMessage = "";

$name = "";
$email = "";
$subject = "";
$message = "";
if (empty($_SESSION['contact_csrf'])) {
    $_SESSION['contact_csrf'] = bin2hex(random_bytes(32));
}

if (!empty($_SESSION['contact_success'])) {
    $successMessage = $_SESSION['contact_success'];
    unset($_SESSION['contact_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['contact_csrf'], $csrfToken)) {

        $errorMessage = "Invalid form submission. Please refresh the page and try again.";

    } elseif (
        $name === '' ||
        $email === '' ||
        $subject === '' ||
        $message === ''
    ) {

        $errorMessage = "Please fill in all fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $errorMessage = "Please enter a valid email address.";

    } elseif (
        mb_strlen($name) > 150 ||
        mb_strlen($email) > 150 ||
        mb_strlen($subject) > 200
    ) {

        $errorMessage = "One or more fields exceed the allowed length.";

    } else {

        try {

            $sql = "INSERT INTO contact_messages
                    (name, email, subject, message)
                    VALUES
                    (:name, :email, :subject, :message)";

            $stmt = $db->prepare($sql);

            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':subject' => $subject,
                ':message' => $message
            ]);

            $_SESSION['contact_success'] =
                "Thank you! Your message has been submitted successfully.";
            $_SESSION['contact_csrf'] = bin2hex(random_bytes(32));

            header("Location: contact-us.php");
            exit;

        } catch (PDOException $e) {

            $errorMessage =
                "Sorry, your message could not be submitted. Please try again.";

        }
    }
}

include "components/header.php";
include "components/sidebar.php";
?>

<main class="contact-page">
    <section class="contact-hero">
        <div class="container text-center">

            <span class="contact-label">WE'RE HERE TO HELP</span>

            <h1>Contact Us</h1>

            <p>
                Have a question, suggestion or need assistance?
                Send us a message and we'll be happy to hear from you.
            </p>

        </div>
    </section>
    <section class="contact-main">
        <div class="container">

            <div class="row g-5">
                <div class="col-lg-5">

                    <span class="contact-subtitle">
                        GET IN TOUCH
                    </span>

                    <h2>We'd Love to Hear From You</h2>

                    <p class="contact-description">
                        Whether you have a question about an order,
                        a product or your shopping experience,
                        you can send us a message using the form.
                    </p>

                    <div class="contact-info-card">

                        <div class="contact-info-icon">
                            <i class="fa-solid fa-headset"></i>
                        </div>

                        <div>
                            <h5>Customer Support</h5>
                            <p>
                                Contact us for help with your shopping
                                experience and general enquiries.
                            </p>
                        </div>

                    </div>

                    <div class="contact-info-card">

                        <div class="contact-info-icon">
                            <i class="fa-solid fa-box"></i>
                        </div>

                        <div>
                            <h5>Order Assistance</h5>
                            <p>
                                Need help with an order?
                                Visit your account to review your orders.
                            </p>

                            <a href="orders.php">View My Orders</a>
                        </div>

                    </div>

                    <div class="contact-info-card">

                        <div class="contact-info-icon">
                            <i class="fa-solid fa-circle-question"></i>
                        </div>

                        <div>
                            <h5>General Enquiries</h5>
                            <p>
                                Share your questions, feedback
                                or suggestions with us.
                            </p>
                        </div>

                    </div>

                </div>
                <div class="col-lg-7">

                    <div class="contact-form-card">

                        <h3>Send Us a Message</h3>

                        <p class="form-description">
                            Fill in the details below and submit your enquiry.
                        </p>

                        <?php if ($successMessage): ?>

                            <div class="alert alert-success">
                                <?= htmlspecialchars($successMessage) ?>
                            </div>

                        <?php endif; ?>

                        <?php if ($errorMessage): ?>

                            <div class="alert alert-danger">
                                <?= htmlspecialchars($errorMessage) ?>
                            </div>

                        <?php endif; ?>

                        <form method="POST" action="contact-us.php">

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars($_SESSION['contact_csrf']) ?>"
                            >

                            <div class="mb-3">

                                <label for="contactName">
                                    Your Name
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="contactName"
                                    name="name"
                                    placeholder="Enter your name"
                                    maxlength="150"
                                    value="<?= htmlspecialchars($name) ?>"
                                    required
                                >

                            </div>

                            <div class="mb-3">

                                <label for="contactEmail">
                                    Email Address
                                </label>

                                <input
                                    type="email"
                                    class="form-control"
                                    id="contactEmail"
                                    name="email"
                                    placeholder="Enter your email"
                                    maxlength="150"
                                    value="<?= htmlspecialchars($email) ?>"
                                    required
                                >

                            </div>

                            <div class="mb-3">

                                <label for="contactSubject">
                                    Subject
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="contactSubject"
                                    name="subject"
                                    placeholder="What is your enquiry about?"
                                    maxlength="200"
                                    value="<?= htmlspecialchars($subject) ?>"
                                    required
                                >

                            </div>

                            <div class="mb-4">

                                <label for="contactMessage">
                                    Your Message
                                </label>

                                <textarea
                                    class="form-control"
                                    id="contactMessage"
                                    name="message"
                                    rows="6"
                                    placeholder="Write your message here..."
                                    required
                                ><?= htmlspecialchars($message) ?></textarea>

                            </div>

                            <button
                                type="submit"
                                class="contact-submit-btn"
                            >
                                Submit Message
                                <i class="fa-solid fa-paper-plane ms-2"></i>
                            </button>

                        </form>

                    </div>

                </div>

            </div>

        </div>
    </section>
    <section class="contact-bottom">
        <div class="container text-center">

            <h2>Thank You for Choosing MyShop.in</h2>

            <p>
                We appreciate your feedback and look forward
                to helping you.
            </p>

            <a href="index.php">
                Continue Shopping
                <i class="fa-solid fa-arrow-right ms-2"></i>
            </a>

        </div>
    </section>

</main>

<?php include "components/footer.php"; ?>
