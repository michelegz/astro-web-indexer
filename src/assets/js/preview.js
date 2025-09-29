document.addEventListener("DOMContentLoaded", () => {
    const overlay = document.getElementById("preview-overlay");
    const overlayImg = document.getElementById("preview-image");

    document.querySelectorAll(".thumb").forEach(img => {
        img.addEventListener("mouseenter", () => {
            overlayImg.src = img.src;  // use the same image (or full if available)
            overlay.classList.remove("hidden");
        });

        img.addEventListener("mousemove", (e) => {
            const offset = 20;
            const ow = overlayImg.width;
            const oh = overlayImg.height;

            let left = e.clientX + offset;
            let top = e.clientY + offset;

            // right edge
            if (left + ow > window.innerWidth) {
                left = e.clientX - ow - offset;
            }
            // bottom edge
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
});