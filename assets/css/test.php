<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<style>
    .header {
        z-index: 10;
        position: absolute;
        top: -45px;
        right: 0;
        left: 0;
        line-height: 1;
        -webkit-tap-highlight-color: rgba(0, 0, 0, 0);
        pointer-events: none;
        transition: transform 140ms ease-in-out;
    }

    button.button--select,
    .button__value,
    .button__icon {
        background-color: #575f5b !important;
    }

    #intro-booking-calendar {
        max-width: 700px;
        margin: 0 auto;
        font-family: inherit;
        color: #575f5b;
    }

    #ibc-error {
        color: #b00020;
        text-align: center;
        margin-bottom: 15px;
    }

    .ibc-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
    }

    .ibc-header button {
        width: 42px;
        height: 42px;
        border: 0;
        background: #575f5b;
        color: #fff;
        font-size: 25px;
        cursor: pointer;
        border-radius: 4px;
    }

    .ibc-header button:disabled {
        opacity: 0.3;
        cursor: not-allowed;
    }

    #ibc-month {
        font-size: 20px;
        font-weight: 600;
    }

    .ibc-weekdays,
    .ibc-days {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 8px;
    }

    .ibc-weekdays {
        margin-bottom: 8px;
        text-align: center;
        font-weight: 600;
        font-size: 14px;
    }

    .ibc-days button,
    .ibc-empty {
        min-height: 55px;
    }

    .ibc-day {
        width: 100%;
        border: 1px solid #ddd;
        background: #f7f7f7;
        color: #999;
        border-radius: 4px;
        cursor: default;
        font-size: 16px;
    }

    .ibc-day:disabled {
        opacity: 1;
    }

    .ibc-day.available {
        background: #575f5b;
        color: #fff;
        border-color: #575f5b;
        cursor: pointer;
        font-weight: 600;
    }

    .ibc-day.available:hover {
        background: #424945;
    }

    .ibc-day.selected {
        outline: 3px solid #c9a66b;
    }

    .ibc-empty {
        border: 0;
    }

    .ibc-times {
        margin-top: 30px;
        text-align: center;
    }

    .ibc-times h3 {
        margin-bottom: 15px;
    }

    .ibc-time-button {
        display: inline-block;
        margin: 5px;
        padding: 12px 20px;
        background: #575f5b;
        color: white;
        border: 0;
        border-radius: 4px;
        cursor: pointer;
        font-size: 15px;
    }

    .ibc-time-button:hover {
        background: #424945;
    }

    .ibc-loading {
        grid-column: 1 / -1;
        text-align: center;
        padding: 20px;
    }


    /* MOBILE */

    @media (max-width: 600px) {

        .ibc-weekdays,
        .ibc-days {
            gap: 4px;
        }

        .ibc-day,
        .ibc-empty {
            min-height: 45px;
        }

        #ibc-month {
            font-size: 17px;
        }

        .ibc-day {
            font-size: 14px;
        }

    }
</style>

<body>
    <div id="intro-booking-calendar">

        <div id="ibc-error"></div>

        <div class="ibc-calendar">

            <div class="ibc-header">
                <button type="button" id="ibc-prev">‹</button>

                <div id="ibc-month"></div>

                <button type="button" id="ibc-next">›</button>
            </div>

            <div class="ibc-weekdays">
                <div>Mon</div>
                <div>Tue</div>
                <div>Wed</div>
                <div>Thu</div>
                <div>Fri</div>
                <div>Sat</div>
                <div>Sun</div>
            </div>

            <div id="ibc-days" class="ibc-days"></div>

        </div>

        <div id="ibc-times" class="ibc-times"></div>

    </div>

    <script>
        (function() {

            const API_KEY = "MS0yMDIwODgwODM3MTk2NjU0OTUzLXQrUHFVaGZhQzZEWU9BSWcvdHpaVnBObjdoNGVMVi81-uk3";

            const BUSINESS_ID =
                "1952402047928118220";

            const PRACTITIONER_ID =
                "1952402043624762063";

            const APPOINTMENT_TYPE_ID =
                "2018724743103916914";

            const CLINIKO_URL =
                "https://api.uk3.cliniko.com/v1/businesses/" +
                BUSINESS_ID +
                "/practitioners/" +
                PRACTITIONER_ID +
                "/appointment_types/" +
                APPOINTMENT_TYPE_ID +
                "/available_times";

            const monthElement =
                document.getElementById("ibc-month");

            const daysElement =
                document.getElementById("ibc-days");

            const timesElement =
                document.getElementById("ibc-times");

            const errorElement =
                document.getElementById("ibc-error");

            const prevButton =
                document.getElementById("ibc-prev");

            const nextButton =
                document.getElementById("ibc-next");

            const today = new Date();

            today.setHours(0, 0, 0, 0);

            let currentMonth =
                new Date(
                    today.getFullYear(),
                    today.getMonth(),
                    1
                );


            let selectedDate = null;

            let availability = {};

            const monthNames = [
                "January",
                "February",
                "March",
                "April",
                "May",
                "June",
                "July",
                "August",
                "September",
                "October",
                "November",
                "December"
            ];

            function getMonthString(date) {

                return (
                    date.getFullYear() +
                    "-" +
                    String(
                        date.getMonth() + 1
                    ).padStart(2, "0")
                );

            }

            function getDateString(date) {

                return (
                    date.getFullYear() +
                    "-" +
                    String(
                        date.getMonth() + 1
                    ).padStart(2, "0") +
                    "-" +
                    String(
                        date.getDate()
                    ).padStart(2, "0")
                );

            }

            function isPast(date) {

                const d =
                    new Date(
                        date.getFullYear(),
                        date.getMonth(),
                        date.getDate()
                    );

                return d < today;

            }

            function sameDate(a, b) {

                return (
                    a.getFullYear() === b.getFullYear() &&
                    a.getMonth() === b.getMonth() &&
                    a.getDate() === b.getDate()
                );

            }


            function getAuthorization() {

                return (
                    "Basic " +
                    btoa(
                        API_KEY + ":"
                    )
                );

            }

            function getChunks(year, month) {

                const chunks = [];

                const firstDay =
                    new Date(
                        year,
                        month,
                        1
                    );

                const lastDay =
                    new Date(
                        year,
                        month + 1,
                        0
                    );


                let start =
                    new Date(firstDay);


                while (start <= lastDay) {

                    let end =
                        new Date(start);

                    end.setDate(
                        end.getDate() + 6
                    );


                    if (end > lastDay) {
                        end = new Date(lastDay);
                    }


                    chunks.push({
                        from: getDateString(start),
                        to: getDateString(end)
                    });


                    start =
                        new Date(end);

                    start.setDate(
                        start.getDate() + 1
                    );

                }


                return chunks;

            }

            async function fetchChunk(from, to) {

                const url =
                    CLINIKO_URL +
                    "?from=" +
                    encodeURIComponent(from) +
                    "&to=" +
                    encodeURIComponent(to) +
                    "&per_page=100";

                console.log("Calling Cliniko:", url);

                try {

                    const response = await fetch(url, {
                        method: "GET",
                        headers: {
                            "Authorization": getAuthorization(),
                            "Accept": "application/json"
                        }
                    });

                    console.log("Cliniko HTTP status:", response.status);
                    console.log("Cliniko response:", response);

                    const responseText = await response.text();

                    console.log(
                        "Cliniko response body:",
                        responseText
                    );

                    if (!response.ok) {
                        throw new Error(
                            "Cliniko HTTP " +
                            response.status +
                            ": " +
                            responseText
                        );
                    }

                    return JSON.parse(responseText);

                } catch (error) {

                    console.error(
                        "CLINIKO REQUEST FAILED:",
                        error
                    );

                    throw error;
                }
            }

            async function loadAvailability() {

                daysElement.innerHTML =
                    '<div class="ibc-loading">Loading availability...</div>';

                timesElement.innerHTML = "";

                errorElement.textContent = "";

                availability = {};


                const year =
                    currentMonth.getFullYear();

                const month =
                    currentMonth.getMonth();


                try {

                    /*
                     * Get month in 7-day chunks
                     */

                    const chunks =
                        getChunks(
                            year,
                            month
                        );


                    /*
                     * Fetch all chunks
                     */

                    const results =
                        await Promise.all(
                            chunks.map(
                                chunk =>
                                fetchChunk(
                                    chunk.from,
                                    chunk.to
                                )
                            )
                        );




                    results.forEach(
                        function(data) {

                            const times =
                                data.available_times || [];


                            times.forEach(
                                function(item) {

                                    if (
                                        !item.appointment_start
                                    ) {
                                        return;
                                    }


                                    const appointment =
                                        new Date(
                                            item.appointment_start
                                        );


                                    const key =
                                        getDateString(
                                            appointment
                                        );


                                    if (
                                        !availability[key]
                                    ) {

                                        availability[key] = [];

                                    }


                                    availability[key].push(
                                        item.appointment_start
                                    );

                                }
                            );

                        }
                    );


                    renderCalendar();

                } catch (error) {

                    console.error("FINAL ERROR:", error);

                    daysElement.innerHTML = "";

                    errorElement.innerHTML =
                        "<strong>Unable to load availability.</strong><br>" +
                        "<small>" +
                        error.message +
                        "</small>";
                }

            }



            function renderCalendar() {

                daysElement.innerHTML = "";


                const year =
                    currentMonth.getFullYear();

                const month =
                    currentMonth.getMonth();


                monthElement.textContent =
                    monthNames[month] +
                    " " +
                    year;


                const currentCalendarMonth =
                    new Date(
                        today.getFullYear(),
                        today.getMonth(),
                        1
                    );


                if (
                    currentMonth <=
                    currentCalendarMonth
                ) {

                    prevButton.disabled =
                        true;

                } else {

                    prevButton.disabled =
                        false;

                }


                let firstDay =
                    new Date(
                        year,
                        month,
                        1
                    ).getDay();


                firstDay =
                    firstDay === 0 ?
                    6 :
                    firstDay - 1;



                for (
                    let i = 0; i < firstDay; i++
                ) {

                    const empty =
                        document.createElement(
                            "div"
                        );

                    empty.className =
                        "ibc-empty";

                    daysElement.appendChild(
                        empty
                    );

                }



                const daysInMonth =
                    new Date(
                        year,
                        month + 1,
                        0
                    ).getDate();


                for (
                    let day = 1; day <= daysInMonth; day++
                ) {

                    const date =
                        new Date(
                            year,
                            month,
                            day
                        );


                    const key =
                        getDateString(
                            date
                        );


                    const button =
                        document.createElement(
                            "button"
                        );


                    button.type =
                        "button";

                    button.className =
                        "ibc-day";

                    button.textContent =
                        day;



                    const isTuesday =
                        date.getDay() === 2;


                    const times =
                        availability[key] || [];


                    const hasAvailability =
                        times.length > 0;


                    const past =
                        isPast(date);


                    if (
                        isTuesday &&
                        hasAvailability &&
                        !past
                    ) {

                        button.classList.add(
                            "available"
                        );


                        button.addEventListener(
                            "click",
                            function() {

                                selectedDate =
                                    date;


                                renderCalendar();


                                showTimes(
                                    date,
                                    times
                                );

                            }
                        );

                    } else {

                        button.disabled =
                            true;

                    }



                    if (
                        selectedDate &&
                        sameDate(
                            date,
                            selectedDate
                        )
                    ) {

                        button.classList.add(
                            "selected"
                        );

                    }


                    daysElement.appendChild(
                        button
                    );

                }

            }


            function showTimes(
                date,
                times
            ) {

                timesElement.innerHTML = "";


                const heading =
                    document.createElement(
                        "h3"
                    );


                heading.textContent =
                    "Available appointments for " +
                    date.toLocaleDateString(
                        "en-GB", {
                            weekday: "long",
                            day: "numeric",
                            month: "long"
                        }
                    );


                timesElement.appendChild(
                    heading
                );



                times.sort(
                    function(a, b) {

                        return (
                            new Date(a) -
                            new Date(b)
                        );

                    }
                );



                times.forEach(
                    function(
                        appointmentStart
                    ) {

                        const appointment =
                            new Date(
                                appointmentStart
                            );


                        const button =
                            document.createElement(
                                "button"
                            );


                        button.type =
                            "button";

                        button.className =
                            "ibc-time-button";


                        button.textContent =
                            appointment.toLocaleTimeString(
                                "en-GB", {
                                    hour: "2-digit",
                                    minute: "2-digit"
                                }
                            );


                        button.addEventListener(
                            "click",
                            function() {



                                console.log(
                                    "Selected appointment:",
                                    appointmentStart
                                );



                            }
                        );


                        timesElement.appendChild(
                            button
                        );

                    }
                );

            }


            prevButton.addEventListener(
                "click",
                function() {

                    if (
                        prevButton.disabled
                    ) {
                        return;
                    }


                    currentMonth =
                        new Date(
                            currentMonth.getFullYear(),
                            currentMonth.getMonth() - 1,
                            1
                        );


                    selectedDate =
                        null;


                    loadAvailability();

                }
            );


            nextButton.addEventListener(
                "click",
                function() {

                    currentMonth =
                        new Date(
                            currentMonth.getFullYear(),
                            currentMonth.getMonth() + 1,
                            1
                        );


                    selectedDate =
                        null;


                    loadAvailability();

                }
            );

            loadAvailability();

        })();
    </script>

</body>

</html>