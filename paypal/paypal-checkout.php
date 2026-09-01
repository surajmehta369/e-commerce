<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>PayPal Checkout Test</title>
    <script src="https://www.paypal.com/sdk/js?client-id=BAAHpGX9MgmkZykVaLi0DkQhyZK9d8yaFvCcjk56dMB-392LSaMvXHC6vJ4CgJ89M0rrRfCQbCN-bsLIDE&currency=USD"></script>

</head>

<body>
<script>

document.addEventListener('DOMContentLoaded', function () {

    const paypalRadio =
        document.getElementById('paypal');

    const paypalContainer =
        document.getElementById('paypal-button-container');

    const checkoutForm =
        document.getElementById('checkoutForm');

    const placeOrderButton =
        document.getElementById('placeOrderButton');

    paypalContainer.style.display = 'none';

    document
        .querySelectorAll('input[name="payment_method"]')
        .forEach(function (radio) {

            radio.addEventListener('change', function () {

                if (paypalRadio.checked) {

                    paypalContainer.style.display = 'block';

                    placeOrderButton.style.display = 'none';

                } else {

                    paypalContainer.style.display = 'none';

                    placeOrderButton.style.display = 'block';

                }

            });

        });

    paypal.Buttons({
        createOrder: function(data, actions) {

            console.log(
                'Creating PayPal order...'
            );

            const cart =
                JSON.parse(
                    localStorage.getItem('cart') || '[]'
                );


            if (!cart.length) {

                alert(
                    'Your cart is empty.'
                );

                throw new Error(
                    'Cart is empty.'
                );

            }

            const shipping = {

                name:
                    document.getElementById(
                        'shipping_name'
                    ).value.trim(),

                phone:
                    document.getElementById(
                        'shipping_phone'
                    ).value.trim(),

                address:
                    document.getElementById(
                        'shipping_address'
                    ).value.trim(),

                city:
                    document.getElementById(
                        'shipping_city'
                    ).value.trim(),

                state:
                    document.getElementById(
                        'shipping_state'
                    ).value.trim(),

                pincode:
                    document.getElementById(
                        'shipping_pincode'
                    ).value.trim()

            };

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
                'create-order.php',
                {

                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json'
                    },

                    body: JSON.stringify({

                        cart: cart,

                        shipping: shipping

                    })

                }
            )

            .then(function(response) {

                return response.json();

            })

            .then(function(orderData) {

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


                return orderData.order.id;

            });

        },

        onApprove: function(data, actions) {

            console.log(
                'PayPal Approved:',
                data
            );


            return fetch(
                'capture-order.php',
                {

                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json'
                    },

                    body: JSON.stringify({

                        orderID:
                            data.orderID

                    })

                }
            )

            .then(function(response) {

                return response.json();

            })

            .then(function(result) {

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


                console.log(
                    'PayPal Payment Completed:',
                    result
                );


                window.location.href =
                    'order-confirmation.php';

            });

        },

        onCancel: function(data) {

            console.log(
                'PayPal checkout cancelled:',
                data
            );

            alert(
                'Payment cancelled.'
            );

        },

        onError: function(error) {

            console.error(
                'PayPal Checkout Error:',
                error
            );

            alert(
                'Something went wrong with PayPal payment.'
            );

        }

    }).render(
        '#paypal-button-container'
    );

});

</script>

</body>

</html>