<?php

require_once "components/header.php";
require_once "components/sidebar.php";

$productId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

?>

<main class="pt-5">

    <div class="container-fluid mt-4">

        <!-- PAGE HEADER -->
        <div class="d-flex justify-content-between align-items-center mb-4">

            <div>
                <h2 class="fw-bold mb-1">
                    Related Products
                </h2>

                <p class="text-muted mb-0">
                    Discover more products you may like.
                </p>
            </div>

            <a
                href="index.php"
                class="btn btn-outline-dark rounded-pill">
                <i class="fa-solid fa-arrow-left me-2"></i>
                Continue Shopping
            </a>

        </div>


        <!-- FILTER AREA -->

        <div class="card border-0 shadow-sm rounded-4 mb-4">

            <div class="card-body">

                <div class="row g-3 align-items-end">

                    <!-- MIN PRICE -->

                    <div class="col-lg-2 col-md-4 col-sm-6">

                        <label
                            for="minPrice"
                            class="form-label fw-semibold">
                            Min Price
                        </label>

                        <input
                            type="number"
                            id="minPrice"
                            class="form-control"
                            placeholder="₹ Min"
                            min="0">

                    </div>


                    <!-- MAX PRICE -->

                    <div class="col-lg-2 col-md-4 col-sm-6">

                        <label
                            for="maxPrice"
                            class="form-label fw-semibold">
                            Max Price
                        </label>

                        <input
                            type="number"
                            id="maxPrice"
                            class="form-control"
                            placeholder="₹ Max"
                            min="0">

                    </div>


                    <div class="col-lg-2 col-md-4 col-sm-6">

                        <label
                            for="minDiscount"
                            class="form-label fw-semibold">
                            Minimum Discount
                        </label>

                        <select
                            id="minDiscount"
                            class="form-select">

                            <option value="">
                                Any Discount
                            </option>

                            <option value="10">
                                10% or more
                            </option>

                            <option value="20">
                                20% or more
                            </option>

                            <option value="30">
                                30% or more
                            </option>

                            <option value="40">
                                40% or more
                            </option>

                            <option value="50">
                                50% or more
                            </option>

                        </select>

                    </div>

                    <div class="col-lg-2 col-md-4 col-sm-6">

                        <label
                            for="brandFilter"
                            class="form-label fw-semibold">
                            Brand
                        </label>

                        <select
                            id="brandFilter"
                            class="form-select">

                            <option value="">
                                All Brands
                            </option>

                        </select>

                    </div>

                    <div class="col-lg-2 col-md-4 col-sm-6">

                        <label
                            for="categoryFilter"
                            class="form-label fw-semibold">
                            Category
                        </label>

                        <select
                            id="categoryFilter"
                            class="form-select">

                            <option value="">
                                All Categories
                            </option>

                        </select>

                    </div>

                    <div class="col-lg-2 col-md-4 col-sm-6">

                        <label
                            for="sortProducts"
                            class="form-label fw-semibold">
                            Sort By
                        </label>

                        <select
                            id="sortProducts"
                            class="form-select">

                            <option value="relevance">
                                Relevance
                            </option>

                            <option value="price_low">
                                Price: Low to High
                            </option>

                            <option value="price_high">
                                Price: High to Low
                            </option>

                            <option value="discount">
                                Highest Discount
                            </option>

                            <option value="newest">
                                Newest
                            </option>

                            <option value="oldest">
                                Oldest
                            </option>

                        </select>

                    </div>

                </div>


                <!-- FILTER ACTIONS -->

                <div class="d-flex gap-2 mt-4">

                    <button
                        type="button"
                        id="applyFilters"
                        class="btn btn-primary rounded-pill px-4">
                        <i class="fa-solid fa-filter me-2"></i>
                        Apply Filters
                    </button>


                    <button
                        type="button"
                        id="clearFilters"
                        class="btn btn-outline-secondary rounded-pill px-4">
                        <i class="fa-solid fa-rotate-left me-2"></i>
                        Clear
                    </button>

                </div>

            </div>

        </div>


        

        <div
            class="d-flex justify-content-between align-items-center mb-3">

            <h4 class="fw-bold mb-0">
                Products
            </h4>

            <span
                id="productCount"
                class="text-muted">
                Loading...
            </span>

        </div>

        <div
            id="productsLoading"
            class="text-center py-5">

            <div
                class="spinner-border text-primary"
                role="status"></div>

            <p class="mt-2 text-muted">
                Loading products...
            </p>

        </div>

        <div
            id="relatedProducts"
            class="row g-4"></div>

        <div
            id="noProducts"
            class="text-center py-5 d-none">

            <div
                class="mb-3"
                style="font-size:50px;">
                <i class="fa-solid fa-box-open text-muted"></i>
            </div>

            <h4>
                No products found
            </h4>

            <p class="text-muted">
                Try changing your filters.
            </p>

        </div>


        <div
            id="pagination"
            class="d-flex justify-content-center mt-5 mb-5"></div>

    </div>

</main>


<script>
    document.addEventListener("DOMContentLoaded", function() {

        const productId = <?= $productId ?>;

        const relatedProducts =
            document.getElementById("relatedProducts");

        const productsLoading =
            document.getElementById("productsLoading");

        const noProducts =
            document.getElementById("noProducts");

        const productCount =
            document.getElementById("productCount");

        const pagination =
            document.getElementById("pagination");

        const minPrice =
            document.getElementById("minPrice");

        const maxPrice =
            document.getElementById("maxPrice");

        const minDiscount =
            document.getElementById("minDiscount");

        const brandFilter =
            document.getElementById("brandFilter");

        const categoryFilter =
            document.getElementById("categoryFilter");

        const sortProducts =
            document.getElementById("sortProducts");

        const applyFilters =
            document.getElementById("applyFilters");

        const clearFilters =
            document.getElementById("clearFilters");


        let currentPage = 1;

        async function loadProducts(page = 1) {

            currentPage = page;

            productsLoading.classList.remove("d-none");

            relatedProducts.innerHTML = "";

            noProducts.classList.add("d-none");

            pagination.innerHTML = "";


            const params = new URLSearchParams();


            params.set(
                "exclude_id",
                productId
            );


            params.set(
                "page",
                page
            );


            params.set(
                "limit",
                12
            );


            if (minPrice.value !== "") {

                params.set(
                    "min_price",
                    minPrice.value
                );

            }


            if (maxPrice.value !== "") {

                params.set(
                    "max_price",
                    maxPrice.value
                );

            }


            if (minDiscount.value !== "") {

                params.set(
                    "min_discount",
                    minDiscount.value
                );

            }


            if (brandFilter.value !== "") {

                params.set(
                    "brand_id",
                    brandFilter.value
                );

            }


            if (categoryFilter.value !== "") {

                params.set(
                    "category_id",
                    categoryFilter.value
                );

            }


            params.set(
                "sort",
                sortProducts.value
            );


            try {

                const response = await fetch(
                    "api/product-filter.php?" +
                    params.toString()
                );


                if (!response.ok) {

                    throw new Error(
                        "HTTP error: " +
                        response.status
                    );

                }


                const data =
                    await response.json();


                if (!data.success) {

                    throw new Error(
                        data.message ||
                        "Unable to load products."
                    );

                }


                productsLoading.classList.add("d-none");


                productCount.textContent =
                    data.pagination.total_products +
                    " product(s)";


                if (
                    data.products.length === 0
                ) {

                    noProducts.classList.remove(
                        "d-none"
                    );

                    return;

                }


                renderProducts(
                    data.products
                );


                renderPagination(
                    data.pagination
                );


            } catch (error) {

                productsLoading.classList.add(
                    "d-none"
                );

                relatedProducts.innerHTML = `

                <div class="col-12">

                    <div class="alert alert-danger">

                        <strong>
                            Unable to load products.
                        </strong>

                        <br>

                        <small>
                            ${escapeHtml(
                                error.message
                            )}
                        </small>

                    </div>

                </div>

            `;

            }

        }

        function renderProducts(products) {

            let html = "";


            products.forEach(function(product) {

                const price =
                    Number(product.price);


                const originalPrice =
                    product.original_price !== null ?
                    Number(product.original_price) :
                    null;


                html += `

                <div
                    class="
                        col-lg-3
                        col-md-4
                        col-sm-6
                        col-12
                    "
                >

                    <div class="shop-card h-100">

                        <div class="shop-card-image">

                            <a
                                href="product-details.php?id=${product.id}"
                                class="text-decoration-none"
                            >

                                <img
                                    src="${escapeHtml(
                                        product.image
                                    )}"
                                    alt="${escapeHtml(
                                        product.title
                                    )}"
                                >

                            </a>

                        </div>


                        <div class="shop-card-body">

                            <h5 class="fw-bold">

                                ${escapeHtml(
                                    product.title
                                )}

                            </h5>


                            <p class="text-muted">

                                ${escapeHtml(
                                    product.description || ""
                                )}

                            </p>


                            <div class="mb-2">

                                <span
                                    class="fw-bold fs-5"
                                >
                                    ₹${price.toLocaleString(
                                        "en-IN",
                                        {
                                            minimumFractionDigits: 2
                                        }
                                    )}
                                </span>

                                ${
                                    originalPrice !== null
                                    ? `
                                        <span
                                            class="text-muted text-decoration-line-through ms-2"
                                        >
                                            ₹${originalPrice.toLocaleString(
                                                "en-IN",
                                                {
                                                    minimumFractionDigits: 2
                                                }
                                            )}
                                        </span>
                                    `
                                    : ""
                                }

                            </div>


                            <div class="mb-3">

                                <span
                                    class="badge bg-success"
                                >
                                    ${Number(
                                        product.discount
                                    )}% OFF
                                </span>

                            </div>


                     <button
    type="button"
    class="shop-btn add-to-cart"
    data-id="${product.id}"
    data-title="${escapeHtml(product.title)}"
    data-price="${product.price}"
    data-image="${escapeHtml(product.image)}"
    data-stock="${product.stock}"
>

                                <i
                                    class="fa-solid
                                           fa-cart-plus
                                           me-2"
                                ></i>

                                Add to Cart

                            </button>

                        </div>

                    </div>

                </div>

            `;

            });


            relatedProducts.innerHTML =
                html;

        }


        // --------------------------------------------------
        // PAGINATION
        // --------------------------------------------------

        function renderPagination(data) {

            if (data.total_pages <= 1) {

                return;

            }


            let html = `

            <nav>

                <ul class="pagination">

        `;


            if (data.has_previous_page) {

                html += `

                <li class="page-item">

                    <button
                        class="page-link"
                        data-page="${data.page - 1}"
                    >
                        Previous
                    </button>

                </li>

            `;

            }


            for (
                let i = 1; i <= data.total_pages; i++
            ) {

                html += `

                <li
                    class="
                        page-item
                        ${i === data.page ? "active" : ""}
                    "
                >

                    <button
                        class="page-link"
                        data-page="${i}"
                    >
                        ${i}
                    </button>

                </li>

            `;

            }


            if (data.has_next_page) {

                html += `

                <li class="page-item">

                    <button
                        class="page-link"
                        data-page="${data.page + 1}"
                    >
                        Next
                    </button>

                </li>

            `;

            }


            html += `

                </ul>

            </nav>

        `;


            pagination.innerHTML =
                html;


            pagination
                .querySelectorAll("[data-page]")
                .forEach(function(button) {

                    button.addEventListener(
                        "click",
                        function() {

                            loadProducts(
                                Number(
                                    this.dataset.page
                                )
                            );

                        }
                    );

                });

        }


        // --------------------------------------------------
        // APPLY FILTERS
        // --------------------------------------------------

        applyFilters.addEventListener(
            "click",
            function() {

                loadProducts(1);

            }
        );


        // --------------------------------------------------
        // SORT
        // --------------------------------------------------

        sortProducts.addEventListener(
            "change",
            function() {

                loadProducts(1);

            }
        );


        // --------------------------------------------------
        // CLEAR FILTERS
        // --------------------------------------------------

        clearFilters.addEventListener(
            "click",
            function() {

                minPrice.value = "";

                maxPrice.value = "";

                minDiscount.value = "";

                brandFilter.value = "";

                categoryFilter.value = "";

                sortProducts.value =
                    "relevance";


                loadProducts(1);

            }
        );


        // --------------------------------------------------
        // ESCAPE HTML
        // --------------------------------------------------

        function escapeHtml(value) {

            const div =
                document.createElement("div");

            div.textContent =
                value ?? "";

            return div.innerHTML;

        }


        // --------------------------------------------------
        // INITIAL LOAD
        // --------------------------------------------------

        loadProducts(1);

    });
</script>


<?php

require_once "components/footer.php";

?>