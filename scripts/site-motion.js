(() => {
  const page = document.querySelector(".home-page");
  if (!page) {
    return;
  }

  const reducedMotion = window.matchMedia(
    "(prefers-reduced-motion: reduce)",
  ).matches;

  page.classList.add("motion-ready");
  if (reducedMotion) {
    page.classList.add("reduce-motion");
    return;
  }

  const revealSections = document.querySelectorAll(
    ".home-page main > section:not(.hero)",
  );
  revealSections.forEach((section, index) => {
    section.classList.add("reveal-section");
    section.style.setProperty("--reveal-delay", `${(index % 2) * 70}ms`);
  });

  const revealObserver = new IntersectionObserver(
    (entries, observer) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) {
          return;
        }
        entry.target.classList.add("is-visible");
        observer.unobserve(entry.target);
      });
    },
    { rootMargin: "0px 0px -12%", threshold: 0.12 },
  );
  revealSections.forEach((section) => revealObserver.observe(section));

  const hero = document.querySelector(".hero");
  const heroMedia = document.querySelector(".hero-media");
  if (!hero || !heroMedia) {
    return;
  }

  let frameRequested = false;
  const updateHeroDepth = () => {
    frameRequested = false;
    const heroBottom = hero.getBoundingClientRect().bottom;
    const depth = heroBottom > 0 ? Math.min(window.scrollY * 0.075, 48) : 48;
    heroMedia.style.setProperty("--hero-depth", `${depth}px`);
  };
  const requestHeroDepth = () => {
    if (frameRequested) {
      return;
    }
    frameRequested = true;
    window.requestAnimationFrame(updateHeroDepth);
  };

  updateHeroDepth();
  window.addEventListener("scroll", requestHeroDepth, { passive: true });
})();
