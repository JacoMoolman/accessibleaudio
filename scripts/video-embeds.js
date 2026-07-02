document.querySelectorAll(".video-card[data-video-id]").forEach((card) => {
  card.addEventListener("click", () => {
    const videoId = card.dataset.videoId;
    if (!videoId || !/^[A-Za-z0-9_-]{11}$/.test(videoId)) {
      return;
    }

    const title = card.getAttribute("aria-label") || "YouTube video sample";
    const iframe = document.createElement("iframe");
    iframe.className = "video-player";
    iframe.src = `https://www.youtube-nocookie.com/embed/${videoId}?autoplay=1&rel=0`;
    iframe.title = title.replace(/^Play\s+/i, "");
    iframe.allow = "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share";
    iframe.allowFullscreen = true;

    card.replaceWith(iframe);
    iframe.focus();
  });
});
