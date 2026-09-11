
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


    function updateCartCount() {

        const cartCountElement =
            document.getElementById("cart-count");

        if (!cartCountElement) {
            return;
        }


        const cart = getCart();

        let totalQuantity = 0;


        cart.forEach(function (product) {

            totalQuantity += Number(product.quantity) || 0;

        });


        cartCountElement.textContent =
            totalQuantity;

    }
    updateCartCount();

    const detailQuantity =
        document.getElementById("detailQuantity");

    const detailQuantityMinus =
        document.getElementById("detailQuantityMinus");

    const detailQuantityPlus =
        document.getElementById("detailQuantityPlus");


    if (detailQuantityMinus && detailQuantity) {

        detailQuantityMinus.addEventListener(
            "click",
            function () {

                let quantity =
                    Number(detailQuantity.value);

                if (
                    !Number.isInteger(quantity) ||
                    quantity < 1
                ) {
                    quantity = 1;
                }

                if (quantity > 1) {
                    quantity--;
                }

                detailQuantity.value = quantity;

            }
        );

    }


    if (detailQuantityPlus && detailQuantity) {

        detailQuantityPlus.addEventListener(
            "click",
            function () {

                let quantity =
                    Number(detailQuantity.value);

                const maxQuantity =
                    Number(detailQuantity.max);

                if (
                    !Number.isInteger(quantity) ||
                    quantity < 1
                ) {
                    quantity = 1;
                }

                if (
                    maxQuantity > 0 &&
                    quantity < maxQuantity
                ) {
                    quantity++;
                }

                detailQuantity.value = quantity;

            }
        );

    }

    if (detailQuantity) {

        detailQuantity.addEventListener(
            "input",
            function () {

                let quantity =
                    Number(this.value);

                const maxQuantity =
                    Number(this.max);

                if (
                    !Number.isInteger(quantity) ||
                    quantity < 1
                ) {
                    quantity = 1;
                }

                if (
                    maxQuantity > 0 &&
                    quantity > maxQuantity
                ) {
                    quantity = maxQuantity;
                }

                this.value = quantity;

            }
        );

    }

    const quantityModalElement =
        document.getElementById("quantityModal");

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


    if (
        quantityModalElement &&
        typeof bootstrap !== "undefined"
    ) {

        quantityModal =
            new bootstrap.Modal(
                quantityModalElement
            );

    }


    document.addEventListener(
        "click",
        function (event) {

            const button =
                event.target.closest(
                    ".add-to-cart"
                );

            if (!button) {
                return;
            }


            event.preventDefault();
            event.stopPropagation();
            selectedProduct = {

                id:
                    String(
                        button.dataset.id
                    ),

                title:
                    button.dataset.title || "",

                price:
                    Number(
                        button.dataset.price
                    ) || 0,

                image:
                    button.dataset.image || "",

                stock:
                    Number(
                        button.dataset.stock
                    ) || 0

            };


            console.log(
                "Add to Cart clicked:",
                selectedProduct
            );
            if (
                quantityModal &&
                quantityInput
            ) {

                if (modalProductImage) {

                    modalProductImage.src =
                        selectedProduct.image;

                    modalProductImage.alt =
                        selectedProduct.title;

                }


                if (modalProductTitle) {

                    modalProductTitle.textContent =
                        selectedProduct.title;

                }


                if (modalProductPrice) {

                    modalProductPrice.textContent =
                        "₹" +
                        selectedProduct.price.toLocaleString(
                            "en-IN",
                            {
                                minimumFractionDigits: 2
                            }
                        );

                }


                quantityInput.value = 1;


                if (selectedProduct.stock > 0) {

                    quantityInput.max =
                        selectedProduct.stock;

                } else {

                    quantityInput.removeAttribute(
                        "max"
                    );

                }


                quantityModal.show();

                return;

            }

            addProductToCart(
                selectedProduct,
                1,
                button
            );

        }
    );

    if (
        quantityMinus &&
        quantityInput
    ) {

        quantityMinus.addEventListener(
            "click",
            function () {

                let quantity =
                    Number(
                        quantityInput.value
                    );


                if (
                    !Number.isInteger(quantity) ||
                    quantity < 1
                ) {

                    quantity = 1;

                }


                if (quantity > 1) {

                    quantity--;

                }


                quantityInput.value =
                    quantity;

            }
        );

    }

    if (
        quantityPlus &&
        quantityInput
    ) {

        quantityPlus.addEventListener(
            "click",
            function () {

                let quantity =
                    Number(
                        quantityInput.value
                    );


                if (
                    !Number.isInteger(quantity) ||
                    quantity < 1
                ) {

                    quantity = 1;

                }


                const maxQuantity =
                    Number(
                        quantityInput.max
                    );


                if (
                    maxQuantity > 0 &&
                    quantity >= maxQuantity
                ) {

                    quantity =
                        maxQuantity;

                } else {

                    quantity++;

                }


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
                    Number(
                        this.value
                    );


                if (
                    !Number.isInteger(quantity) ||
                    quantity < 1
                ) {

                    quantity = 1;

                }


                const maxQuantity =
                    Number(
                        this.max
                    );


                if (
                    maxQuantity > 0 &&
                    quantity > maxQuantity
                ) {

                    quantity =
                        maxQuantity;

                }


                this.value =
                    quantity;

            }
        );

    }

    if (confirmAddToCart) {

        confirmAddToCart.addEventListener(
            "click",
            function () {

                if (!selectedProduct) {

                    console.error(
                        "No product selected."
                    );

                    return;

                }


                let quantity =
                    Number(
                        quantityInput
                            ? quantityInput.value
                            : 1
                    );


                if (
                    !Number.isInteger(quantity) ||
                    quantity < 1
                ) {

                    alert(
                        "Please enter a valid quantity."
                    );

                    return;

                }


                if (
                    selectedProduct.stock > 0 &&
                    quantity > selectedProduct.stock
                ) {

                    alert(
                        "Only " +
                        selectedProduct.stock +
                        " items are available in stock."
                    );

                    if (quantityInput) {

                        quantityInput.value =
                            selectedProduct.stock;

                    }

                    return;

                }

                addProductToCart(
                    selectedProduct,
                    quantity
                );

                if (quantityModal) {

                    quantityModal.hide();

                }


                selectedProduct = null;

            }
        );

    }


    function addProductToCart(
        product,
        quantity,
        button = null
    ) {

        if (!product) {

            return;

        }


        let cart =
            getCart();


        const productId =
            String(
                product.id
            );


        const existingProduct =
            cart.find(
                function (item) {

                    return String(
                        item.id
                    ) === productId;

                }
            );

        if (existingProduct) {

            const newQuantity =
                Number(
                    existingProduct.quantity
                ) +
                Number(quantity);


            if (
                product.stock > 0 &&
                newQuantity > product.stock
            ) {

                alert(
                    "You already have " +
                    existingProduct.quantity +
                    " in your cart. Only " +
                    product.stock +
                    " items are available."
                );

                return;

            }


            existingProduct.quantity =
                newQuantity;

        }


        else {

            cart.push({

                id:
                    productId,

                title:
                    product.title,

                price:
                    Number(
                        product.price
                    ),

                image:
                    product.image,

                quantity:
                    Number(
                        quantity
                    )

            });

        }
        saveCart(cart);
        updateCartCount();

        Swal.fire({
            icon: "success",
            title: "Added to Cart",
            text: "Your item has been added to the cart.",
            confirmButtonText: "View Cart",
            showCancelButton: true,
            cancelButtonText: "Continue Shopping",
            confirmButtonColor: "#4f9cff",
            cancelButtonColor: "#6c757d",
            reverseButtons: true
        }).then((result) => {

            if (result.isConfirmed) {
                window.location.href = "cart.php";
            }

        });



        console.log(
            "Product added successfully:",
            product
        );

        console.log(
            "Updated cart:",
            cart
        );

        if (button) {

            const originalText =
                button.innerHTML;


            button.innerHTML =
                '<i class="fa-solid fa-check me-2"></i> Added to Cart';


            button.disabled = true;


            setTimeout(
                function () {

                    button.innerHTML =
                        originalText;

                    button.disabled = false;

                },
                1500
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



    if (!searchForm || !searchInput) {
        console.error("Search form or input not found.");
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

        if (!searchResults || !defaultProducts) {
            console.error(
                "Search results or default products container not found."
            );
            return;
        }


        console.log(
            "Searching for:",
            query
        );


        const startTime = performance.now();

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

            const response = await fetch(
                "/e-commerce/api/search.php?q=" +
                encodeURIComponent(query)
            );


            if (!response.ok) {

                throw new Error(
                    "HTTP error: " +
                    response.status
                );

            }


            const responseText =
                await response.text();


            console.log(
                "Raw API response:",
                responseText
            );


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

            const endTime =
                performance.now();


            const searchTime =
                (endTime - startTime).toFixed(2);

            if (!data.success) {

                throw new Error(
                    data.message ||
                    "Search failed."
                );

            }


            let wordsHtml = "-";


            if (
                Array.isArray(data.words) &&
                data.words.length > 0
            ) {

                wordsHtml = data.words
                    .map(function (word) {

                        return escapeHtml(word);

                    })
                    .join(", ");

            }

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

            data.products.forEach(
                function (product) {

                    html += `

                    <div
                        class="col-lg-4
                               col-md-6
                               col-sm-12"
                    >

                        <div class="shop-card">


                            <!-- IMAGE -->

                           <div class="shop-card-image">

    <a
        href="product-details.php?id=${encodeURIComponent(product.id)}"
        class="text-decoration-none"
    >

        <img
            src="${escapeHtml(product.image)}"
            alt="${escapeHtml(product.title)}"
        >

    </a>

</div>

                            <!-- BODY -->

                            <div class="shop-card-body">


                                <!-- TITLE -->

                               <h4>

    <a
        href="product-details.php?id=${encodeURIComponent(product.id)}"
        class="text-decoration-none text-dark"
    >

        ${escapeHtml(product.title)}

    </a>

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

                                        ${product.relevance_score
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

            html += `

                </div>

            </div>

        `;

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



document.addEventListener("DOMContentLoaded", function () {

    const relatedContainer =
        document.getElementById("relatedProducts");

    if (!relatedContainer) {
        return;
    }

    const productId =
        relatedContainer.dataset.productId;

    if (!productId) {
        return;
    }

    loadRelatedProducts(productId);


    async function loadRelatedProducts(productId) {

        try {

            const response = await fetch(
                "api/product-filter.php?exclude_id=" +
                encodeURIComponent(productId) +
                "&limit=4"
            );


            if (!response.ok) {

                throw new Error(
                    "HTTP error: " + response.status
                );

            }


            const data =
                await response.json();


            if (!data.success) {

                throw new Error(
                    data.message ||
                    "Unable to load related products."
                );

            }


            if (
                !Array.isArray(data.products) ||
                data.products.length === 0
            ) {

                relatedContainer.innerHTML = `

                    <div class="col-12">

                        <div class="text-center py-5">

                            <h5>
                                No related products found.
                            </h5>

                            <p class="text-muted">
                                We couldn't find any products related
                                to this item.
                            </p>

                        </div>

                    </div>

                `;

                return;
            }


            let html = "";


            data.products.forEach(function (product) {

                html += `

                    <div class="
                        col-lg-3
                        col-md-6
                        col-sm-12
                    ">

                        <div class="shop-card h-100">

                            <div class="shop-card-image">

                                <a
                                    href="product-details.php?id=${product.id}"
                                >

                                    <img
                                        src="${escapeHtml(product.image)}"
                                        alt="${escapeHtml(product.title)}"
                                    >

                                </a>

                            </div>


                            <div class="shop-card-body">

                                <h4>

                                    <a
                                        href="product-details.php?id=${product.id}"
                                        class="text-decoration-none text-dark"
                                    >
                                        ${escapeHtml(product.title)}
                                    </a>

                                </h4>


                                <p>

                                    ${escapeHtml(
                    product.description || ""
                )}

                                </p>


                                <h5 class="mb-3">

                                    ₹${Number(
                    product.price
                ).toLocaleString("en-IN", {
                    minimumFractionDigits: 2
                })}

                                </h5>


                                ${Number(product.discount) > 0
                        ?
                        `
                                    <div class="mb-3">

                                        <span class="badge bg-success">

                                            ${Number(
                            product.discount
                        )}% OFF

                                        </span>

                                    </div>
                                    `
                        :
                        ""
                    }


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
                                        class="
                                            fa-solid
                                            fa-cart-plus
                                            me-2
                                        "
                                    ></i>

                                    Add to Cart

                                </button>

                            </div>

                        </div>

                    </div>

                `;

            });


            relatedContainer.innerHTML = html;


        } catch (error) {

            console.error(
                "Related products error:",
                error
            );


            relatedContainer.innerHTML = `

                <div class="col-12">

                    <div class="alert alert-danger">

                        Unable to load related products.

                    </div>

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


document.addEventListener("DOMContentLoaded", function () {

    const editAddress =
        document.getElementById("edit-address");


    if (editAddress) {

        editAddress.scrollIntoView({
            behavior: "smooth",
            block: "start"
        });

    }

});

document.addEventListener('DOMContentLoaded', function () {

    console.log('PayPal checkout script loaded');


    const paypalRadio =
        document.getElementById('paypal-payment');

    const paypalContainer =
        document.getElementById(
            'paypal-button-container'
        );

    const placeOrderButton =
        document.getElementById(
            'placeOrderButton'
        );




    if (!paypalRadio) {

        console.error(
            'PayPal radio button not found.'
        );

        return;

    }


    if (!paypalContainer) {

        console.error(
            'PayPal button container not found.'
        );

        return;

    }


    if (!placeOrderButton) {

        console.error(
            'Place order button not found.'
        );

        return;

    }

    paypalContainer.style.display =
        'none';

    document
        .querySelectorAll(
            'input[name="payment_method"]'
        )
        .forEach(function (radio) {

            radio.addEventListener(
                'change',
                function () {

                    console.log(
                        'Payment method:',
                        this.value
                    );


                    if (
                        paypalRadio.checked
                    ) {

                        paypalContainer.style.display =
                            'block';

                        placeOrderButton.style.display =
                            'none';

                    } else {

                        paypalContainer.style.display =
                            'none';

                        placeOrderButton.style.display =
                            'block';

                    }

                }
            );

        });


    if (
        typeof paypal === 'undefined'
    ) {

        console.error(
            'PayPal SDK was not loaded.'
        );

        return;

    }


    console.log(
        'PayPal SDK loaded successfully.'
    );

    console.log('PayPal object:', paypal);
    console.log('PayPal Buttons:', paypal.Buttons);
    console.log('PayPal type:', typeof paypal);
    console.log('Buttons type:', typeof paypal.Buttons);

    paypal.Buttons({

        createOrder: function (
            data,
            actions
        ) {

            console.log(
                'Creating PayPal order...'
            );
            const cart =
                JSON.parse(
                    localStorage.getItem(
                        'cart'
                    ) || '[]'
                );


            console.log(
                'Cart:',
                cart
            );


            if (
                !cart.length
            ) {

                alert(
                    'Your cart is empty.'
                );

                throw new Error(
                    'Cart is empty.'
                );

            }

            const shipping = {

                name: document
                    .getElementById(
                        'shipping_name'
                    )
                    .value
                    .trim(),

                phone: document
                    .getElementById(
                        'shipping_phone'
                    )
                    .value
                    .trim(),

                address: document
                    .getElementById(
                        'shipping_address'
                    )
                    .value
                    .trim(),

                city: document
                    .getElementById(
                        'shipping_city'
                    )
                    .value
                    .trim(),

                state: document
                    .getElementById(
                        'shipping_state'
                    )
                    .value
                    .trim(),

                pincode: document
                    .getElementById(
                        'shipping_pincode'
                    )
                    .value
                    .trim()

            };


            console.log(
                'Shipping:',
                shipping
            );

            if (

                !shipping.name ||

                !shipping.phone ||

                !shipping.address ||

                !shipping.city ||

                !shipping.state ||

                !shipping.pincode

            ) {

                alert(
                    'Please fill all delivery information.'
                );

                throw new Error(
                    'Shipping information is incomplete.'
                );

            }

            return fetch(
                'paypal/create-order.php', {
                method: 'POST',

                headers: {
                    'Content-Type': 'application/json'
                },

                body: JSON.stringify({
                    cart: cart,
                    shipping: shipping
                })
            }
            )
                .then(function (response) {

                    console.log(
                        'create-order.php status:',
                        response.status
                    );

                    return response.text()
                        .then(function (text) {

                            console.log(
                                'RAW create-order.php RESPONSE:',
                                text
                            );

                            if (!response.ok) {
                                throw new Error(
                                    'create-order.php failed with HTTP ' +
                                    response.status +
                                    ': ' +
                                    text
                                );
                            }

                            try {
                                return JSON.parse(text);
                            } catch (error) {

                                throw new Error(
                                    'Invalid JSON returned by create-order.php: ' +
                                    text
                                );

                            }

                        });

                })
                .then(function (orderData) {

                    console.log(
                        'PayPal Create Order Response:',
                        orderData
                    );

                    if (!orderData.success) {

                        throw new Error(
                            orderData.message ||
                            'Unable to create PayPal order.'
                        );

                    }

                    if (
                        !orderData.order ||
                        !orderData.order.id
                    ) {

                        throw new Error(
                            'PayPal order ID is missing.'
                        );

                    }

                    console.log(
                        'PayPal Order ID:',
                        orderData.order.id
                    );

                    return orderData.order.id;

                });


        },

        onApprove: function (
            data,
            actions
        ) {

            console.log(
                'PayPal Approved:',
                data
            );


            return fetch(
                'paypal/capture-order.php', {
                method: 'POST',

                headers: {
                    'Content-Type': 'application/json'
                },

                body: JSON.stringify({
                    orderID: data.orderID
                })
            }
            )
                .then(function (response) {

                    console.log(
                        'capture-order.php status:',
                        response.status
                    );

                    return response.text()
                        .then(function (text) {

                            console.log(
                                'RAW capture-order.php RESPONSE:',
                                text
                            );

                            if (!response.ok) {
                                throw new Error(
                                    'capture-order.php failed with HTTP ' +
                                    response.status +
                                    ': ' +
                                    text
                                );
                            }

                            try {
                                return JSON.parse(text);

                            } catch (error) {

                                throw new Error(
                                    'Invalid JSON returned by capture-order.php: ' +
                                    text
                                );
                            }
                        });
                })
                .then(function (result) {

                    console.log(
                        'PayPal Capture Result:',
                        result
                    );

                    if (!result.success) {

                        throw new Error(
                            result.message ||
                            'Payment capture failed.'
                        );
                    }

                    window.location.href =
                        'order-confirmation.php';

                });


        },

        onCancel: function (
            data
        ) {

            console.log(
                'PayPal checkout cancelled:',
                data
            );

            alert(
                'Payment cancelled.'
            );

        },

        onError: function (error) {

            console.error('========== PAYPAL ERROR ==========');
            console.error(error);
            console.error('===================================');

            alert(
                'PayPal Error: ' +
                (error.message || 'Unknown error')
            );

        }

    }).render(
        '#paypal-button-container'
    );


    console.log(
        'PayPal Buttons initialized.'
    );

});


document.addEventListener('DOMContentLoaded', function () {

    console.log('Razorpay checkout script loaded.');

    const razorpayRadio =
        document.getElementById('razorpay-payment');

    const checkoutForm =
        document.getElementById('checkoutForm');

    const placeOrderButton =
        document.getElementById('placeOrderButton');

    if (!razorpayRadio) {

        console.log(
            'Razorpay payment option not found. Skipping Razorpay.'
        );

        return;
    }


    if (!checkoutForm) {

        console.error(
            'Checkout form not found.'
        );

        return;
    }


    if (!placeOrderButton) {

        console.error(
            'Place order button not found.'
        );

        return;
    }
    function loadRazorpaySDK() {

        return new Promise(function (resolve, reject) {

            if (
                typeof Razorpay !== 'undefined'
            ) {

                console.log(
                    'Razorpay SDK already loaded.'
                );

                resolve();

                return;
            }


            const script =
                document.createElement('script');

            script.src =
                'https://checkout.razorpay.com/v1/checkout.js';

            script.onload = function () {

                console.log(
                    'Razorpay SDK loaded successfully.'
                );

                resolve();

            };


            script.onerror = function () {

                console.error(
                    'Unable to load Razorpay SDK.'
                );

                reject(
                    new Error(
                        'Unable to load Razorpay SDK.'
                    )
                );

            };


            document.head.appendChild(script);

        });

    }


    document.querySelectorAll('input[name="payment_method"]').forEach(function (radio) {

            radio.addEventListener(
                'change',
                function () {

                    console.log(
                        'Payment method selected:',
                        this.value
                    );

                }
            );

        });

    checkoutForm.addEventListener(
        'submit',
        async function (event) {

            if (!razorpayRadio.checked) {

                return;

            }

            event.preventDefault();


            console.log(
                'Razorpay payment selected.'
            );

            placeOrderButton.disabled =
                true;

            placeOrderButton.innerHTML =
                '<i class="fa-solid fa-spinner fa-spin me-2"></i>' +
                'Processing...';


            try {

            
                await loadRazorpaySDK();

                const cart =
                    JSON.parse(
                        localStorage.getItem('cart')
                        || '[]'
                    );


                console.log(
                    'Razorpay Cart:',
                    cart
                );


                if (
                    !Array.isArray(cart) ||
                    cart.length === 0
                ) {

                    throw new Error(
                        'Your cart is empty.'
                    );

                }

                const shipping = {

                    name:
                        document
                            .getElementById(
                                'shipping_name'
                            )
                            .value
                            .trim(),

                    phone:
                        document
                            .getElementById(
                                'shipping_phone'
                            )
                            .value
                            .trim(),

                    address:
                        document
                            .getElementById(
                                'shipping_address'
                            )
                            .value
                            .trim(),

                    city:
                        document
                            .getElementById(
                                'shipping_city'
                            )
                            .value
                            .trim(),

                    state:
                        document
                            .getElementById(
                                'shipping_state'
                            )
                            .value
                            .trim(),

                    pincode:
                        document
                            .getElementById(
                                'shipping_pincode'
                            )
                            .value
                            .trim()

                };


                console.log(
                    'Razorpay Shipping:',
                    shipping
                );

                if (

                    !shipping.name ||
                    !shipping.phone ||
                    !shipping.address ||
                    !shipping.city ||
                    !shipping.state ||
                    !shipping.pincode

                ) {

                    throw new Error(
                        'Please complete all delivery information.'
                    );

                }

                console.log(
                    'Calling razorpay/create-order.php...'
                );


                const response =
                    await fetch(
                        'razorpay/create-order.php',
                        {
                            method: 'POST',

                            headers: {
                                'Content-Type':
                                    'application/json'
                            },

                            body: JSON.stringify({

                                cart:
                                    cart,

                                shipping:
                                    shipping

                            })

                        }
                    );


                console.log(
                    'Razorpay create-order status:',
                    response.status
                );


                const text =
                    await response.text();


                console.log(
                    'RAW Razorpay response:',
                    text
                );


                if (!response.ok) {

                    throw new Error(
                        'Razorpay create-order.php failed: '
                        + text
                    );

                }


                let orderData;


                try {

                    orderData =
                        JSON.parse(text);

                } catch (error) {

                    throw new Error(
                        'Invalid JSON returned by Razorpay create-order.php.'
                    );

                }


                console.log(
                    'Razorpay Create Order Response:',
                    orderData
                );

                if (!orderData.success) {

                    throw new Error(
                        orderData.message ||
                        'Unable to create Razorpay order.'
                    );

                }


                if (
                    !orderData.razorpay_order_id
                ) {

                    throw new Error(
                        'Razorpay Order ID is missing.'
                    );

                }


                if (
                    !orderData.key_id
                ) {

                    throw new Error(
                        'Razorpay Key ID is missing.'
                    );

                }


                console.log(
                    'Razorpay Order ID:',
                    orderData.razorpay_order_id
                );
                const options = {

                    key:
                        orderData.key_id,

                    amount:
                        orderData.amount,

                    currency:
                        orderData.currency,

                    name:
                        'MyShop.in',

                    description:
                        'E-commerce Order',

                    order_id:
                        orderData.razorpay_order_id,

                    prefill: {

                        name:
                            shipping.name,

                        contact:
                            shipping.phone

                    },

                    notes: {

                        local_order_id:
                            String(
                                orderData.local_order_id
                            )

                    },
                    handler:
                        async function (
                            razorpayResponse
                        ) {

                            console.log(
                                'Razorpay Payment Success:',
                                razorpayResponse
                            );


                            try {
                                const verifyResponse =
                                    await fetch(
                                        'razorpay/verify-payment.php',
                                        {
                                            method: 'POST',

                                            headers: {

                                                'Content-Type':
                                                    'application/json'

                                            },

                                            body:
                                                JSON.stringify({

                                                    razorpay_order_id:
                                                        razorpayResponse
                                                            .razorpay_order_id,

                                                    razorpay_payment_id:
                                                        razorpayResponse
                                                            .razorpay_payment_id,

                                                    razorpay_signature:
                                                        razorpayResponse
                                                            .razorpay_signature

                                                })

                                        }
                                    );


                                const verifyText =
                                    await verifyResponse.text();


                                console.log(
                                    'RAW Razorpay verification response:',
                                    verifyText
                                );


                                if (
                                    !verifyResponse.ok
                                ) {

                                    throw new Error(
                                        'Payment verification failed: '
                                        + verifyText
                                    );

                                }


                                let verifyData;


                                try {

                                    verifyData =
                                        JSON.parse(
                                            verifyText
                                        );

                                } catch (error) {

                                    throw new Error(
                                        'Invalid JSON from verify-payment.php.'
                                    );

                                }


                                console.log(
                                    'Razorpay Verification Result:',
                                    verifyData
                                );


                                if (
                                    !verifyData.success
                                ) {

                                    throw new Error(
                                        verifyData.message ||
                                        'Payment verification failed.'
                                    );

                                }

                                window.location.href =
                                    'order-confirmation.php';

                            } catch (error) {

                                console.error(
                                    'Razorpay verification error:',
                                    error
                                );


                                alert(
                                    error.message ||
                                    'Payment verification failed.'
                                );


                                placeOrderButton.disabled =
                                    false;

                                placeOrderButton.innerHTML =
                                    '<i class="fa-solid fa-lock me-2"></i>' +
                                    'Continue to Payment';

                            }

                        },
                    modal: {

                        ondismiss:
                            function () {

                                console.log(
                                    'Razorpay checkout closed.'
                                );


                                placeOrderButton.disabled =
                                    false;

                                placeOrderButton.innerHTML =
                                    '<i class="fa-solid fa-lock me-2"></i>' +
                                    'Continue to Payment';

                            }

                    }

                };

                console.log(
                    'Opening Razorpay Checkout...'
                );


                const razorpay =
                    new Razorpay(options);


                razorpay.on(
                    'payment.failed',
                    function (response) {

                        console.error(
                            'Razorpay Payment Failed:',
                            response
                        );


                        alert(
                            response.error &&
                                response.error.description
                                ? response.error.description
                                : 'Payment failed.'
                        );


                        placeOrderButton.disabled =
                            false;

                        placeOrderButton.innerHTML =
                            '<i class="fa-solid fa-lock me-2"></i>' +
                            'Continue to Payment';

                    }
                );


                razorpay.open();


            } catch (error) {

                console.error(
                    '========== RAZORPAY ERROR =========='
                );

                console.error(error);

                console.error(
                    '===================================='
                );


                alert(
                    error.message ||
                    'Unable to start Razorpay payment.'
                );


                placeOrderButton.disabled =
                    false;

                placeOrderButton.innerHTML =
                    '<i class="fa-solid fa-lock me-2"></i>' +
                    'Continue to Payment';

            }

        });

});
