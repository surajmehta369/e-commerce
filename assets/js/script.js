document.addEventListener("DOMContentLoaded", function () {

    const allBtn = document.getElementById("allMenuBtn");
    const sidebar = document.getElementById("sidebar");
    const overlay = document.getElementById("sidebarOverlay");
    const closeBtn = document.getElementById("closeSidebar");


    if (allBtn) {
        allBtn.addEventListener("click", function (e) {
            e.preventDefault();

            sidebar.classList.add("open");
            overlay.classList.add("show");
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener("click", function () {
            sidebar.classList.remove("open");
            overlay.classList.remove("show");
        });
    }

    if (overlay) {
        overlay.addEventListener("click", function () {
            sidebar.classList.remove("open");
            overlay.classList.remove("show");
        });
    }

    function getCart() {
        return JSON.parse(localStorage.getItem("cart")) || [];
    }


    function saveCart(cart) {
        localStorage.setItem("cart", JSON.stringify(cart));
    }

    // =====================================================
// UPDATE CART COUNT
// =====================================================

function updateCartCount() {

    const cartCountElement =
        document.getElementById("cart-count");

    if (!cartCountElement) {
        return;
    }


    const cart = getCart();


    // Calculate total quantity

    let totalQuantity = 0;


    cart.forEach(function (product) {

        totalQuantity += Number(product.quantity) || 0;

    });


    cartCountElement.textContent =
        totalQuantity;

}
updateCartCount();






    const addToCartButtons =
        document.querySelectorAll(".add-to-cart");

    const quantityModalElement =
        document.getElementById("quantityModal");


    if (
        quantityModalElement &&
        addToCartButtons.length > 0
    ) {

        const quantityInput =
            document.getElementById("quantityInput");

        const quantityMinus =
            document.getElementById("quantityMinus");

        const quantityPlus =
            document.getElementById("quantityPlus");

        const confirmAddToCart =
            document.getElementById("confirmAddToCart");

        const modalProductImage =
            document.getElementById("modalProductImage");

        const modalProductTitle =
            document.getElementById("modalProductTitle");

        const modalProductPrice =
            document.getElementById("modalProductPrice");


        let selectedProduct = null;



        let quantityModal = null;

        if (typeof bootstrap !== "undefined") {

            quantityModal =
                new bootstrap.Modal(
                    quantityModalElement
                );

        } else {

            console.error(
                "Bootstrap JavaScript is not loaded."
            );

        }

        addToCartButtons.forEach(function (button) {

            button.addEventListener(
                "click",
                function () {

                    selectedProduct = {

                        id: this.dataset.id,

                        title: this.dataset.title,

                        price: Number(
                            this.dataset.price
                        ),

                        image: this.dataset.image

                    };




                    modalProductImage.src =
                        selectedProduct.image;

                    modalProductImage.alt =
                        selectedProduct.title;


                    modalProductTitle.textContent =
                        selectedProduct.title;


                    modalProductPrice.textContent =
                        "₹" +
                        selectedProduct.price
                            .toLocaleString("en-IN");



                    quantityInput.value = 1;




                    if (quantityModal) {

                        quantityModal.show();

                    }

                }
            );

        });


        if (quantityMinus) {

            quantityMinus.addEventListener(
                "click",
                function () {

                    let quantity =
                        Number(quantityInput.value);


                    if (quantity > 1) {

                        quantity--;

                    }


                    quantityInput.value =
                        quantity;

                }
            );

        }

        if (quantityPlus) {

            quantityPlus.addEventListener(
                "click",
                function () {

                    let quantity =
                        Number(quantityInput.value);


                    quantity++;


                    quantityInput.value =
                        quantity;

                }
            );

        }



        if (quantityInput) {

            quantityInput.addEventListener(
                "input",
                function () {

                    let quantity =
                        Number(this.value);


                    if (
                        !Number.isInteger(quantity) ||
                        quantity < 1
                    ) {

                        this.value = 1;

                    }

                }
            );

        }

        if (confirmAddToCart) {

            confirmAddToCart.addEventListener(
                "click",
                function () {

                    if (!selectedProduct) {

                        return;

                    }


                    let quantity =
                        Number(quantityInput.value);


                    if (
                        !Number.isInteger(quantity) ||
                        quantity < 1
                    ) {

                        alert(
                            "Please enter a valid quantity."
                        );

                        return;

                    }


                    let cart = getCart();

                    const product = {

                        id: selectedProduct.id,

                        title: selectedProduct.title,

                        price: selectedProduct.price,

                        image: selectedProduct.image,

                        quantity: quantity

                    };



                    const existingProduct =
                        cart.find(function (item) {

                            return item.id === product.id;

                        });


                    if (existingProduct) {

                        existingProduct.quantity +=
                            quantity;

                    } else {

                        cart.push(product);

                    }


                    saveCart(cart);

                    updateCartCount();

                    if (quantityModal) {

                        quantityModal.hide();

                    }


                    alert(
                        "Product added to cart!"
                    );

                }
            );

        }

    }


    const cartContainer =
        document.getElementById("cart-container");


    if (cartContainer) {

        displayCart();

    }

    function displayCart() {

        const cart = getCart();


        if (cart.length === 0) {

            cartContainer.innerHTML = `

                <div class="card border-0
                            shadow-sm rounded-4
                            text-center p-5">

                    <div class="mb-4">

                        <div
                            class="bg-light rounded-circle
                                   d-inline-flex
                                   align-items-center
                                   justify-content-center"
                            style="
                                width:110px;
                                height:110px;
                            "
                        >

                            <i
                                class="fa-solid
                                       fa-cart-shopping
                                       text-primary"
                                style="
                                    font-size:48px;
                                "
                            ></i>

                        </div>

                    </div>


                    <h2 class="fw-bold">
                        Your Cart is Empty
                    </h2>


                    <p class="text-muted mb-4">

                        You haven't added any products
                        to your cart yet.

                    </p>


                    <a
                        href="index.php"
                        class="btn btn-primary
                               rounded-pill px-4 py-2"
                    >

                        <i
                            class="fa-solid
                                   fa-bag-shopping me-2"
                        ></i>

                        Start Shopping

                    </a>

                </div>

            `;

            return;

        }

        let html = `

            <div class="card border-0
                        shadow-sm rounded-4 p-4">

                <h2 class="fw-bold mb-4">

                    <i
                        class="fa-solid
                               fa-cart-shopping
                               text-primary me-2"
                    ></i>

                    Your Cart

                </h2>

        `;


        let grandTotal = 0;


        cart.forEach(function (product) {

            const subtotal =
                product.price * product.quantity;


            grandTotal += subtotal;


            html += `

                <div
                    class="cart-item
                           d-flex
                           align-items-center
                           justify-content-between
                           border-bottom
                           py-3"
                >

                    <div
                        class="d-flex
                               align-items-center"
                    >

                        <img
                            src="${product.image}"
                            alt="${product.title}"
                            style="
                                width:90px;
                                height:90px;
                                object-fit:cover;
                                border-radius:10px;
                            "
                        >


                        <div class="ms-3">

                            <h5 class="mb-1">
                                ${product.title}
                            </h5>


                            <p class="text-muted mb-1">

                                Price:
                                ₹${product.price
                    .toLocaleString("en-IN")}

                            </p>


                           <p class="mb-2">
    Quantity:
</p>

<div class="d-flex align-items-center">

    <button
        type="button"
        class="btn btn-outline-secondary btn-sm cart-quantity-minus"
        data-id="${product.id}"
        style="width:32px; height:32px;"
    >
        −
    </button>


    <span
        class="mx-3 fw-bold cart-quantity"
        style="min-width:20px; text-align:center;"
    >
        ${product.quantity}
    </span>


    <button
        type="button"
        class="btn btn-outline-primary btn-sm cart-quantity-plus"
        data-id="${product.id}"
        style="width:32px; height:32px;"
    >
        +
    </button>

</div>

                        </div>

                    </div>

                

                    <div class="text-end">

                        <h5 class="mb-2">

                            ₹${subtotal
                    .toLocaleString("en-IN")}

                        </h5>


                        <button
                            type="button"
                            class="btn btn-danger
                                   btn-sm remove-from-cart"
                            data-id="${product.id}"
                        >

                            <i class="fa-solid fa-trash"></i>

                            Remove

                        </button>

                    </div>

                </div>

            `;

        });

        html += `

                <div
                    class="d-flex
                           justify-content-between
                           align-items-center
                           mt-4"
                >

                    <h4 class="fw-bold mb-0">
                        Total
                    </h4>


                    <h4
                        class="fw-bold
                               text-primary
                               mb-0"
                    >

                        ₹${grandTotal
                .toLocaleString("en-IN")}

                    </h4>

    <div class="text-end mt-4">

    <a
        href="checkout.php"
        class="btn btn-dark rounded-pill px-4 py-2"
    >
        <i class="fa-solid fa-lock me-2"></i>
        Proceed to Checkout
    </a>

</div>


                </div>

            </div>

        `;


        cartContainer.innerHTML = html;

        // remove item

        const removeButtons =
            document.querySelectorAll(
                ".remove-from-cart"
            );


        removeButtons.forEach(function (button) {

            button.addEventListener(
                "click",
                function () {

                    const productId =
                        this.dataset.id;


                    let updatedCart =
                        getCart();


                    updatedCart =
                        updatedCart.filter(
                            function (product) {

                                return product.id !==
                                    productId;

                            }
                        );


                    saveCart(updatedCart);

                    updateCartCount();


                    displayCart();

                }
            );

        });

const plusButtons =
    document.querySelectorAll(".cart-quantity-plus");


plusButtons.forEach(function (button) {

    button.addEventListener("click", function () {

        const productId =
            this.dataset.id;


        let updatedCart = getCart();


        const product =
            updatedCart.find(function (item) {

                return item.id === productId;

            });


        if (product) {

            product.quantity++;

        }


        saveCart(updatedCart);

        updateCartCount();

        displayCart();

    });

});


const minusButtons =
    document.querySelectorAll(".cart-quantity-minus");


minusButtons.forEach(function (button) {

    button.addEventListener("click", function () {

        const productId =
            this.dataset.id;


        let updatedCart = getCart();


        const product =
            updatedCart.find(function (item) {

                return item.id === productId;

            });


        if (product) {


            if (product.quantity > 1) {

                product.quantity--;

            }

        }


        saveCart(updatedCart);

        updateCartCount();

        displayCart();

    });

});


    }

});


document.addEventListener(
    "DOMContentLoaded",
    function () {

        loadCheckoutCart();

    }
);


function loadCheckoutCart() {

    const container =
        document.getElementById("checkout-cart");


    const cart =
        JSON.parse(
            localStorage.getItem("cart")
        ) || [];


    // Empty cart

    if (cart.length === 0) {

        container.innerHTML = `

            <div class="text-center py-4">

                <i
                    class="fa-solid
                           fa-cart-shopping
                           text-muted"
                    style="font-size:40px;"
                ></i>

                <p class="text-muted mt-3">

                    Your cart is empty.

                </p>

                <a
                    href="index.php"
                    class="btn btn-primary
                           rounded-pill"
                >
                    Continue Shopping
                </a>

            </div>

        `;

        return;

    }


    let html = "";

    let total = 0;


    cart.forEach(function (product) {

        const subtotal =
            Number(product.price) *
            Number(product.quantity);


        total += subtotal;


        html += `

            <div
                class="d-flex
                       align-items-center
                       border-bottom
                       pb-3 mb-3"
            >

                <img
                    src="${product.image}"
                    alt="${product.title}"
                    style="
                        width:70px;
                        height:70px;
                        object-fit:cover;
                        border-radius:10px;
                    "
                >


                <div class="ms-3 flex-grow-1">

                    <h6 class="mb-1">

                        ${product.title}

                    </h6>


                    <small class="text-muted">

                        ₹${Number(product.price)
                            .toLocaleString("en-IN")}

                        ×

                        ${product.quantity}

                    </small>

                </div>


                <strong>

                    ₹${subtotal
                        .toLocaleString("en-IN")}

                </strong>

            </div>

        `;

    });


    html += `

        <div
            class="d-flex
                   justify-content-between
                   align-items-center
                   pt-2"
        >

            <h5 class="fw-bold mb-0">

                Total

            </h5>


            <h4
                class="fw-bold
                       text-primary
                       mb-0"
            >

                ₹${total
                    .toLocaleString("en-IN")}

            </h4>

        </div>

    `;


    container.innerHTML = html;

}

const checkoutForm =
    document.getElementById("checkoutForm");


if (checkoutForm) {

    checkoutForm.addEventListener(
        "submit",
        function () {

            const cart =
                JSON.parse(
                    localStorage.getItem("cart")
                ) || [];


            const cartInput =
                document.getElementById("cart_data");


            if (!cartInput) {

                return;

            }


            cartInput.value =
                JSON.stringify(cart);

        }
    );

}



document.addEventListener("DOMContentLoaded", function () {

    const searchForm =
        document.getElementById("searchForm");

    const searchInput =
        document.getElementById("searchInput");

    const searchResults =
        document.getElementById("searchResults");

    const defaultProducts =
        document.getElementById("defaultProducts");



    if (
        !searchForm ||
        !searchInput ||
        !searchResults ||
        !defaultProducts
    ) {

        console.error("Search elements not found.");

        return;

    }


    let searchTimer = null;

    searchInput.addEventListener(
        "input",
        function () {

            const query =
                this.value.trim();



            clearTimeout(searchTimer);


            if (query === "") {

                searchResults.style.display =
                    "none";

                defaultProducts.style.display =
                    "block";

                searchResults.innerHTML = "";

                return;

            }

            searchTimer = setTimeout(
                function () {

                    performSearch(query);

                },
                400
            );

        }
    );

    searchForm.addEventListener(
        "submit",
        function (event) {

            event.preventDefault();

        }
    );
async function performSearch(query) {

    console.log(
        "Searching for:",
        query
    );


    // --------------------------------
    // START SEARCH TIMER
    // --------------------------------

    const startTime = performance.now();


    // --------------------------------
    // SHOW SEARCH AREA
    // --------------------------------

    searchResults.style.display = "block";

    defaultProducts.style.display = "none";


    searchResults.innerHTML = `

        <div class="text-center py-5">

            <div
                class="spinner-border text-primary"
                role="status"
            ></div>

            <p class="mt-2">
                Searching...
            </p>

        </div>

    `;


    try {

        // --------------------------------
        // CALL SEARCH API
        // --------------------------------

        const response = await fetch(
            "api/search.php?q=" +
            encodeURIComponent(query)
        );


        // Check HTTP status

        if (!response.ok) {

            throw new Error(
                "HTTP error: " +
                response.status
            );

        }


        // --------------------------------
        // GET RAW RESPONSE
        // --------------------------------

        const responseText =
            await response.text();


        console.log(
            "Raw API response:",
            responseText
        );


        // --------------------------------
        // CONVERT JSON
        // --------------------------------

        let data;


        try {

            data = JSON.parse(responseText);

        } catch (jsonError) {

            console.error(
                "Invalid JSON:",
                responseText
            );

            throw new Error(
                "Server returned invalid JSON."
            );

        }


        console.log(
            "Search response:",
            data
        );


        // --------------------------------
        // END SEARCH TIMER
        // --------------------------------

        const endTime =
            performance.now();


        const searchTime =
            (endTime - startTime).toFixed(2);


        // --------------------------------
        // CHECK API SUCCESS
        // --------------------------------

        if (!data.success) {

            throw new Error(
                data.message ||
                "Search failed."
            );

        }


        // --------------------------------
        // SEARCH INFORMATION
        // --------------------------------

        let wordsHtml = "-";


        if (
            Array.isArray(data.words) &&
            data.words.length > 0
        ) {

            wordsHtml = data.words
                .map(function(word) {

                    return escapeHtml(word);

                })
                .join(", ");

        }


        // --------------------------------
        // NO RESULTS
        // --------------------------------

        if (data.count === 0) {

            searchResults.innerHTML = `

                <div class="container-fluid">

                    <div class="card shadow-sm mb-4">

                        <div class="card-body">

                            <h5 class="mb-3">
                                Search Information
                            </h5>

                            <div class="row g-3">

                                <div class="col-lg-3 col-md-6">

                                    <div class="border rounded p-3">

                                        <small class="text-muted">
                                            Query
                                        </small>

                                        <div class="fw-bold">
                                            ${escapeHtml(
                                                data.query || query
                                            )}
                                        </div>

                                    </div>

                                </div>


                                <div class="col-lg-3 col-md-6">

                                    <div class="border rounded p-3">

                                        <small class="text-muted">
                                            Search Words
                                        </small>

                                        <div class="fw-bold">
                                            ${wordsHtml}
                                        </div>

                                    </div>

                                </div>


                                <div class="col-lg-3 col-md-6">

                                    <div class="border rounded p-3">

                                        <small class="text-muted">
                                            Total Results
                                        </small>

                                        <div class="fw-bold fs-4">
                                            0
                                        </div>

                                    </div>

                                </div>


                                <div class="col-lg-3 col-md-6">

                                    <div class="border rounded p-3">

                                        <small class="text-muted">
                                            Search Time
                                        </small>

                                        <div class="fw-bold">
                                            ${searchTime} ms
                                        </div>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>


                    <div class="text-center py-5">

                        <h4>
                            No products found
                        </h4>

                        <p class="text-muted">

                            No products matched
                            "${escapeHtml(query)}"

                        </p>

                    </div>

                </div>

            `;

            return;
        }


        // --------------------------------
        // BUILD SEARCH RESULTS
        // --------------------------------

        let html = `

            <div class="container-fluid">


                <!-- SEARCH ENGINE INFORMATION -->

                <div class="card shadow-sm mb-4">

                    <div class="card-body">

                        <h5 class="mb-3">

                            Search Engine Information

                        </h5>


                        <div class="row g-3">


                            <!-- QUERY -->

                            <div class="col-lg-3 col-md-6">

                                <div class="border rounded p-3">

                                    <small class="text-muted">
                                        Query
                                    </small>

                                    <div class="fw-bold">

                                        ${escapeHtml(
                                            data.query || query
                                        )}

                                    </div>

                                </div>

                            </div>


                            <!-- WORDS -->

                            <div class="col-lg-3 col-md-6">

                                <div class="border rounded p-3">

                                    <small class="text-muted">
                                        Search Words
                                    </small>

                                    <div class="fw-bold">

                                        ${wordsHtml}

                                    </div>

                                </div>

                            </div>


                            <!-- TOTAL -->

                            <div class="col-lg-3 col-md-6">

                                <div class="border rounded p-3">

                                    <small class="text-muted">
                                        Total Results
                                    </small>

                                    <div class="fw-bold fs-4">

                                        ${data.count}

                                    </div>

                                </div>

                            </div>


                            <!-- TIME -->

                            <div class="col-lg-3 col-md-6">

                                <div class="border rounded p-3">

                                    <small class="text-muted">
                                        Search Time
                                    </small>

                                    <div class="fw-bold">

                                        ${searchTime} ms

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- SEARCH RESULT HEADER -->

                <div
                    class="d-flex
                           justify-content-between
                           align-items-center
                           mb-3"
                >

                    <h3>
                        Search Results
                    </h3>

                    <span class="text-muted">

                        ${data.count}
                        product(s)

                    </span>

                </div>


                <div class="row g-4">

        `;


        // --------------------------------
        // PRODUCTS
        // --------------------------------

        data.products.forEach(
            function(product) {

                html += `

                    <div
                        class="col-lg-4
                               col-md-6
                               col-sm-12"
                    >

                        <div class="shop-card">


                            <!-- IMAGE -->

                            <div class="shop-card-image">

                                <img
                                    src="${escapeHtml(
                                        product.image
                                    )}"

                                    alt="${escapeHtml(
                                        product.title
                                    )}"
                                >

                            </div>


                            <!-- BODY -->

                            <div class="shop-card-body">


                                <!-- TITLE -->

                                <h4>

                                    ${escapeHtml(
                                        product.title
                                    )}

                                </h4>


                                <!-- DESCRIPTION -->

                                <p>

                                    ${escapeHtml(
                                        product.description
                                    )}

                                </p>


                                <!-- PRICE -->

                                <h5 class="mb-3">

                                    ₹${Number(
                                        product.price
                                    ).toFixed(2)}

                                </h5>


                                <!-- RELEVANCE SCORE -->

                                <div class="mb-3">

                                    <small class="text-muted">
                                        Relevance Score
                                    </small>

                                    <strong class="text-success ms-1">

                                        ${
                                            product.relevance_score
                                            ?? 0
                                        }

                                    </strong>

                                </div>


                                <!-- ADD TO CART -->

                                <button
                                    type="button"
                                    class="shop-btn add-to-cart"

                                    data-id="${product.id}"

                                    data-title="${escapeHtml(
                                        product.title
                                    )}"

                                    data-price="${product.price}"

                                    data-image="${escapeHtml(
                                        product.image
                                    )}"
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

            }
        );


        // --------------------------------
        // CLOSE HTML
        // --------------------------------

        html += `

                </div>

            </div>

        `;


        // --------------------------------
        // DISPLAY RESULTS
        // --------------------------------

        searchResults.innerHTML = html;


    } catch (error) {

        console.error(
            "Search error:",
            error
        );


        searchResults.innerHTML = `

            <div class="alert alert-danger">

                <strong>
                    Unable to search products.
                </strong>

                <br>

                <small>
                    ${escapeHtml(
                        error.message
                    )}
                </small>

            </div>

        `;

    }

}



    function escapeHtml(value) {

        const div =
            document.createElement("div");

        div.textContent =
            value ?? "";

        return div.innerHTML;

    }

});

