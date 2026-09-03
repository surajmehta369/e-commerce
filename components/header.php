<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$isLoggedIn = isset($_SESSION['user_id']);
?>

<!doctype html>
<html lang="en" data-bs-theme="light">

<head>

  <title>MyShop.in</title>

  <meta charset="utf-8">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <link rel="stylesheet" href="assets/css/style.css">
   <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

   <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


</head>

<body>

  <header>

    <nav class="navbar navbar-expand-lg navbar-dark myshop-navbar fixed-top">

      <div class="container-fluid px-3 px-lg-4">
        <a
          class="navbar-brand myshop-logo"
          href="index.php">
          MyShop<span>.in</span>
        </a>
        <button
          class="navbar-toggler"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#mainNavbar"
          aria-controls="mainNavbar"
          aria-expanded="false"
          aria-label="Toggle navigation">

          <span class="navbar-toggler-icon"></span>

        </button>
        <div
          class="collapse navbar-collapse"
          id="mainNavbar">

          <ul class="navbar-nav me-lg-3 mb-2 mb-lg-0">

            <li class="nav-item">

              <a
                class="nav-link"
                href="#"
                id="allMenuBtn">
                <i class="fa-solid fa-bars me-1"></i>
                All
              </a>

            </li>


            <li class="nav-item">

              <a
                class="nav-link"
                href="#">
                Sell
              </a>

            </li>


            <li class="nav-item">

              <a
                class="nav-link"
                href="#">
                Best Sellers
              </a>

            </li>


            <li class="nav-item">

              <a
                class="nav-link"
                href="#todayDeals">
                Today's Deals
              </a>

            </li>


            <li class="nav-item">

              <a
                class="nav-link"
                href="#">
                Mobiles
              </a>

            </li>

          </ul>

          <form
            class="d-flex flex-grow-1 myshop-search mb-3 mb-lg-0"
            id="searchForm"
            action="/e-commerce/api/search.php"
            method="GET">

            <input
              class="form-control"
              type="search"
              id="searchInput"
              name="q"
              placeholder="Search"
              aria-label="Search"
              autocomplete="off">


            <button
              class="btn search-btn"
              type="submit"
              aria-label="Search">

              <i class="fa-solid fa-magnifying-glass"></i>

            </button>

          </form>

          <div class="myshop-actions ms-lg-3">


            <?php if ($isLoggedIn): ?>
              <a
                href="account.php"
                class="header-action">

                <i class="fa-solid fa-user"></i>

                <span>
                  Account
                </span>

              </a>
              <a
                href="cart.php"
                class="header-action cart-action"
                title="View Cart">

                <i class="fa-solid fa-cart-shopping"></i>

                <span>
                  Cart
                </span>


                <span
                  id="cart-count"
                  class="cart-count">
                  0
                </span>

              </a>

              <a
                href="outh/logout.php"
                class="logout-btn"
                title="Logout"
                aria-label="Logout">

                <i class="fa-solid fa-right-from-bracket"></i>

              </a>


            <?php else: ?>
              <a
                href="outh/login.php"
                class="header-action">

                <i class="fa-solid fa-user"></i>

                <span>
                  Sign In
                </span>

              </a>
              <a
                href="outh/register.php"
                class="signup-btn">
                Sign Up
              </a>
              <a
                href="cart.php"
                class="header-action cart-action"
                title="View Cart">

                <i class="fa-solid fa-cart-shopping"></i>

                <span>
                  Cart
                </span>


                <span
                  id="cart-count"
                  class="cart-count">
                  0
                </span>

              </a>


            <?php endif; ?>

          </div>

        </div>

      </div>

    </nav>

  </header>