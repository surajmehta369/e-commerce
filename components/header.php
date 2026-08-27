

<!doctype html>
<html lang="en" data-bs-theme="light">

<head>
  <title>E-commerce</title>
  <!-- Required meta tags -->
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <!-- Bootstrap CSS v5.3.8 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">

  <link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
    crossorigin="anonymous"></script>


</head>

<body>
  <header>
    <nav class="navbar navbar-expand-lg fixed-top navbar-dark" style="background-color:rgb(35, 47, 62);">
      <a class="navbar-brand" href="index.php">Myshop.in</a>
      <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent"
        aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse" id="navbarSupportedContent">
        <ul class="navbar-nav mr-auto">
          <li class="nav-item active">
            <a class="nav-link" href="#" id="allMenuBtn"> <i class="fa-solid fa-bars">&nbsp;All</i></a>
          </li>

          <li class="nav-item">
            <a class="nav-link" href="#">sell</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="#">Best seller</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="#todayDeals"  >Today deals</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="#">Mobile</a>
          </li>

          <form class="d-flex  my-2 my-lg-0">
            <input class="form-control me-2" type="search" placeholder="Search" aria-label="Search">
            <button class="btn btn-outline-light" type="submit">
              Search
            </button>
          </form>
        </ul>
      </div>
<?php if (isset($_SESSION['user_id'])): ?>

    <a href="outh/logout.php"
       class="btn btn-danger"
       title="Logout"
       aria-label="Logout">
        <i class="fa-solid fa-right-from-bracket"></i>
    </a>

<?php else: ?>

    <!-- Sign Up -->
     
<a
    href="orders.php"
    class="btn btn-light rounded-pill"
>
    <i class="fa-solid fa-box me-2"></i>
    My Orders
</a>

&nbsp;
    <a href="outh/register.php"
       class="btn btn-light"
       title="Sign Up"
       aria-label="Sign Up">
        <i class="fa-solid fa-user-plus"></i>
    </a>
&nbsp;
 
<a href="cart.php"
   class="btn btn-light position-relative"
   title="View Cart"
   aria-label="View Cart">

    <i class="fa-solid fa-cart-plus"></i>

    <span
        id="cart-count"
        class="position-absolute top-0 start-100
               translate-middle badge rounded-pill
               bg-danger"
        style="font-size: 10px;"
    >
        0
    </span>

</a>



<?php endif; ?>
    </nav>
  </header>