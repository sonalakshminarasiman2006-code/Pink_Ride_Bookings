document.addEventListener("DOMContentLoaded", function () {

    // Mobile navigation
    const menuButton = document.querySelector(".menu-toggle");
    const navigation = document.querySelector(".main-navigation");

    if (menuButton && navigation) {
        menuButton.addEventListener("click", function () {
            navigation.classList.toggle("show");
        });
    }


    // Password visibility
    const passwordToggles =
        document.querySelectorAll(".password-toggle");

    passwordToggles.forEach(function (toggle) {

        toggle.addEventListener("click", function () {

            const input =
                document.querySelector(
                    toggle.dataset.target
                );

            if (!input) return;

            if (input.type === "password") {
                input.type = "text";
                toggle.textContent = "Hide";
            } else {
                input.type = "password";
                toggle.textContent = "Show";
            }

        });

    });


    // Auto hide alerts
    const alerts =
        document.querySelectorAll(".alert");

    alerts.forEach(function (alert) {

        setTimeout(function () {

            alert.style.opacity = "0";

            setTimeout(function () {
                alert.remove();
            }, 400);

        }, 4000);

    });


    // Prevent double form submission
    const forms =
        document.querySelectorAll("form");

    forms.forEach(function (form) {

        form.addEventListener("submit", function () {

            const button =
                form.querySelector("button[type='submit']");

            if (button) {

                button.disabled = true;

                button.dataset.originalText =
                    button.textContent;

                button.textContent =
                    "Please wait...";

            }

        });

    });


    // Smooth scrolling
    document.querySelectorAll(
        'a[href^="#"]'
    ).forEach(function (link) {

        link.addEventListener("click", function (event) {

            const targetId =
                link.getAttribute("href");

            const target =
                document.querySelector(targetId);

            if (target) {

                event.preventDefault();

                target.scrollIntoView({
                    behavior: "smooth"
                });

            }

        });

    });

});