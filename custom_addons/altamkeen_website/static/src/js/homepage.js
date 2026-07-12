document.addEventListener("DOMContentLoaded", () => {
    const companyName = "AL TAMKEEN CORPORATE SERVICES DWC LLC";
    const primaryPhone = "+971 55 154 5947";
    const primaryPhoneHref = "tel:+971551545947";
    const logoUrl = "/altamkeen_website/static/src/img/al-tamkeen-logo.png";
    const companyIntro =
        "AL TAMKEEN is a UAE-based corporate services provider supporting businesses, investors, entrepreneurs, and individuals with professional, client-focused solutions.";
    const revealItems = document.querySelectorAll(
        ".alt-section, .alt-update-strip, .alt-about, .alt-band, .alt-legacy, .alt-docs, .alt-contact, .alt-service-request, .alt-final-cta, .alt-service-card, .alt-process > div"
    );
    const navLinks = document.querySelectorAll('header#top .nav-link[href^="#"], header#top .nav-link[href^="/#"]');
    const serviceLinks = {
        "business setup": "/services/business-setup",
        "pro services": "/services/pro-services",
        "visa services": "/services/visa-services",
        "legal and attestation": "/services/document-attestation",
        "legal coordination": "/services/document-attestation",
        "real estate and investment": "/services/real-estate-investment",
        "hr consultancy": "/services/hr-consultancy",
        "hr and recruitment": "/services/hr-consultancy",
        "global immigration": "/services/global-immigration",
        "travel and tourism": "/services/travel-tourism",
        "insurance services": "/services/insurance-services",
        "driving and vehicle services": "/services/driving-vehicle-services",
        "corporate advisory": "/services/business-advisory",
        "business advisory": "/services/business-advisory",
    };
    const serviceImages = {
        "business setup": "/altamkeen_website/static/src/img/card-business-setup.jpg",
        "pro services": "/altamkeen_website/static/src/img/card-pro.jpg",
        "visa services": "/altamkeen_website/static/src/img/card-visa.jpg",
        "legal and attestation": "/altamkeen_website/static/src/img/card-legal.jpg",
        "legal coordination": "/altamkeen_website/static/src/img/card-legal.jpg",
        "real estate and investment": "/altamkeen_website/static/src/img/card-real-estate.jpg",
        "global immigration": "/altamkeen_website/static/src/img/card-visa.jpg",
        "travel and tourism": "/altamkeen_website/static/src/img/card-visa.jpg",
        "insurance services": "/altamkeen_website/static/src/img/card-corporate.jpg",
        "driving and vehicle services": "/altamkeen_website/static/src/img/card-pro.jpg",
        "hr consultancy": "/altamkeen_website/static/src/img/card-corporate.jpg",
        "hr and recruitment": "/altamkeen_website/static/src/img/card-corporate.jpg",
        "corporate advisory": "/altamkeen_website/static/src/img/card-corporate.jpg",
        "business advisory": "/altamkeen_website/static/src/img/card-corporate.jpg",
    };
    const replaceText = (root, replacements) => {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const nodes = [];

        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }

        nodes.forEach((node) => {
            let value = node.nodeValue;
            replacements.forEach(([from, to]) => {
                value = value.replaceAll(from, to);
            });
            node.nodeValue = value;
        });
    };
    const setActiveLink = (hash) => {
        navLinks.forEach((link) => {
            const linkHash = new URL(link.getAttribute("href"), window.location.origin).hash;
            link.classList.toggle("active", Boolean(hash) && linkHash === hash);
        });
    };

    navLinks.forEach((link) => {
        link.addEventListener("click", () => {
            const hash = new URL(link.getAttribute("href"), window.location.origin).hash;
            setActiveLink(hash);
        });
    });

    replaceText(document.body, [
        ["+1 555-555-5556", primaryPhone],
        ["Copyright © Company name", `Copyright © ${companyName}`],
    ]);

    document.querySelectorAll('a[href^="tel:+1555"], a[href^="tel:+1"]').forEach((link) => {
        if (link.textContent.includes("555")) {
            link.href = primaryPhoneHref;
        }
    });

    document.querySelectorAll("header#top .navbar-brand img, header#top img[alt*='Logo'], header#top img[alt*='logo']").forEach((logo) => {
        logo.src = logoUrl;
        logo.alt = "AL TAMKEEN CORPORATE SERVICES DWC LLC";
    });

    document.querySelectorAll(".alt-panel-header strong").forEach((label) => {
        if (label.textContent.trim() === "SmartServe") {
            label.textContent = "AL TAMKEEN";
        }
    });

    document.querySelectorAll("footer p").forEach((paragraph) => {
        if (paragraph.textContent.includes("small to medium size companies")) {
            paragraph.textContent = companyIntro;
        }
    });

    document.querySelectorAll("footer a").forEach((link) => {
        const label = link.textContent.trim().toLowerCase();
        const footerLinks = {
            "home": { text: "Home", href: "/" },
            "about us": { text: "About", href: "/#about" },
            "about": { text: "About", href: "/#about" },
            "products": { text: "Why Choose Us", href: "/#why" },
            "services": { text: "Services", href: "/#services" },
            "contact us": { text: "Contact Us", href: "/#contact" },
        };

        if (label === "legal") {
            const item = link.closest("li") || link;
            item.remove();
            return;
        }

        if (footerLinks[label]) {
            link.textContent = footerLinks[label].text;
            link.href = footerLinks[label].href;
        }
    });

    document.querySelectorAll(".alt-service-card").forEach((card) => {
        const title = card.querySelector("h3")?.textContent.trim().toLowerCase();
        const url = serviceLinks[title];
        const imageUrl = serviceImages[title];

        if (!url) {
            return;
        }

        card.dataset.url = url;

        if (imageUrl && !card.querySelector(".alt-service-image")) {
            const image = document.createElement("img");
            image.className = "alt-service-image";
            image.src = imageUrl;
            image.alt = card.querySelector("h3")?.textContent.trim() || "AL TAMKEEN service";
            card.prepend(image);
        }

        if (!card.querySelector(".alt-learn-more")) {
            const link = document.createElement("a");
            link.className = "alt-learn-more";
            link.href = url;
            link.textContent = "Learn More";
            card.appendChild(link);
        }
    });

    const serviceCards = document.querySelectorAll(".alt-service-card[data-url]");

    serviceCards.forEach((card) => {
        card.setAttribute("role", "link");
        card.setAttribute("tabindex", "0");

        const openCard = () => {
            window.location.href = card.dataset.url;
        };

        card.addEventListener("click", (event) => {
            if (event.target.closest("a")) {
                return;
            }
            openCard();
        });

        card.addEventListener("keydown", (event) => {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                openCard();
            }
        });
    });

    const backTop = document.querySelector(".alt-back-top");

    if (backTop) {
        const toggleBackTop = () => {
            backTop.classList.toggle("is-visible", window.scrollY > 520);
        };

        window.addEventListener("scroll", toggleBackTop, { passive: true });
        toggleBackTop();
    }

    revealItems.forEach((item) => item.classList.add("alt-reveal"));

    if (!("IntersectionObserver" in window)) {
        revealItems.forEach((item) => item.classList.add("is-visible"));
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add("is-visible");
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.12 }
    );

    revealItems.forEach((item) => observer.observe(item));
});
