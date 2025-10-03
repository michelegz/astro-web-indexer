document.addEventListener("DOMContentLoaded", () => {
    const overlay = document.getElementById("preview-overlay");
    const overlayImg = document.getElementById("preview-image");

    if (!overlay || !overlayImg) {
        return;
    }

    const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;

    if (isTouchDevice) {
        // --- LOGICA PER DISPOSITIVI TOUCH ---
        let isOverlayVisible = false;

        document.querySelectorAll(".thumb").forEach(img => {
            img.addEventListener("click", (e) => {
                e.preventDefault();
                e.stopPropagation();

                if (isOverlayVisible) {
                    overlay.classList.add("hidden");
                    isOverlayVisible = false;
                } else {
                    overlayImg.src = img.src;
                    overlay.classList.remove("hidden");

                    // Centra l'overlay dopo che è visibile
                    setTimeout(() => {
                        const vh = window.innerHeight;
                        const vw = window.innerWidth;
                        const oh = overlay.offsetHeight;
                        const ow = overlay.offsetWidth;
                        
                        overlay.style.top = `${(vh - oh) / 2}px`;
                        overlay.style.left = `${(vw - ow) / 2}px`;
                    }, 0);

                    isOverlayVisible = true;
                }
            });
        });

        // Nascondi l'overlay se si tocca in qualsiasi altro punto
        document.addEventListener("click", () => {
            if (isOverlayVisible) {
                overlay.classList.add("hidden");
                isOverlayVisible = false;
            }
        });

    } else {
        // --- LOGICA ORIGINALE PER DESKTOP (HOVER) ---
        document.querySelectorAll(".thumb").forEach(img => {
            img.addEventListener("mouseenter", () => {
                overlayImg.src = img.src;
                overlay.classList.remove("hidden");
            });

            img.addEventListener("mousemove", (e) => {
                const offset = 20;
                const ow = overlay.offsetWidth;
                const oh = overlay.offsetHeight;

                let left = e.clientX + offset;
                let top = e.clientY + offset;

                if (left + ow > window.innerWidth) {
                    left = e.clientX - ow - offset;
                }
                if (top + oh > window.innerHeight) {
                    top = e.clientY - oh - offset;
                }

                overlay.style.left = left + "px";
                overlay.style.top = top + "px";
            });

            img.addEventListener("mouseleave", () => {
                overlay.classList.add("hidden");
            });
        });
    }
});