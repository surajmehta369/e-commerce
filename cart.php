<?php
include "components/header.php";
include "components/sidebar.php";
?>

<main class="container py-5">

    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">

            <div class="card border-0 shadow-sm rounded-4 text-center p-5">

                <div class="mb-4">
                    <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center"
                         style="width: 110px; height: 110px;">
                        <i class="fa-solid fa-cart-shopping text-primary"
                           style="font-size: 48px;"></i>
                    </div>
                </div>

                <h2 class="fw-bold">Your Cart is Empty</h2>

                <p class="text-muted mb-4">
                    You haven't added any products to your cart yet.
                    Start shopping and find something you love!
                </p>

                <a href="index.php"
                   class="btn btn-primary rounded-pill px-4 py-2">
                    <i class="fa-solid fa-bag-shopping me-2"></i>
                    Start Shopping
                </a>

            </div>

        </div>
    </div>

</main>

<?php
include "components/footer.php";
?>